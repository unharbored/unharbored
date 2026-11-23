#!/usr/bin/env bash
#
# Live test of a running control plane, through its public API only.
#
#   scripts/live/run.sh                  all scenarios: static, go, laravel
#   scripts/live/run.sh static go        some of them
#   scripts/live/run.sh sweep            only delete leftovers from earlier runs
#
# It makes ONE real server for the run (on whichever cloud account the control plane
# has: a few cents for a 10 to 20 minute run), puts each scenario's application on it,
# checks the address answers over HTTPS, then deletes everything it made, whether the
# run passed, failed or was interrupted. Everything is named live-<epoch>-<scenario>,
# and every run first sweeps anything named live-* that is more than an hour old, so
# a run that was killed cannot leave a server billing for long.
#
# Needs a token with the manage scope (console, API tokens). Put these in
# scripts/live/local.env (not committed) or the environment:
#
#   CM_URL=https://cp.example.com
#   CM_TOKEN=cm_...
#   LIVE_REGION=nyc3 LIVE_SIZE=s-1vcpu-2gb      optional
#   LIVE_KEEP=1                                 optional: skip cleanup, to look at a failure
#
# The scenario repositories are the GitHub App ones: <owner>/cm-test-static,
# cm-test-go and cm-test-laravel (override with LIVE_REPO_STATIC, _GO, _LARAVEL).
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "$here/local.env" ] && . "$here/local.env"
: "${CM_URL:?set CM_URL and CM_TOKEN, or put them in scripts/live/local.env}"
: "${CM_TOKEN:?set CM_URL and CM_TOKEN, or put them in scripts/live/local.env}"
CM_URL="${CM_URL%/}"
REGION="${LIVE_REGION:-nyc3}"
SIZE="${LIVE_SIZE:-s-1vcpu-2gb}"
REPO_STATIC="${LIVE_REPO_STATIC:?set LIVE_REPO_STATIC (owner/repo)}"
REPO_GO="${LIVE_REPO_GO:?set LIVE_REPO_GO (owner/repo)}"
REPO_LARAVEL="${LIVE_REPO_LARAVEL:?set LIVE_REPO_LARAVEL (owner/repo)}"
command -v jq >/dev/null || { echo "jq is needed" >&2; exit 2; }

NOW=$(date +%s)
RUN="live-$NOW"
MADE_SITES=()      # applications this run made, in order
NODE=""            # the server this run made
FAILED=()
SWEEP_AGE=3600

say() { printf '%s  %s\n' "$(date +%H:%M:%S)" "$*"; }

# api METHOD PATH [JSON]: prints the body; returns non-zero unless the status is 2xx.
api() {
    local method="$1" path="$2" body="${3:-}" out code
    if [ -n "$body" ]; then
        out=$(curl -sS -X "$method" "$CM_URL/api/v1$path" -H "Authorization: Bearer $CM_TOKEN" -H 'Content-Type: application/json' -d "$body" -w '\n%{http_code}') || return 1
    else
        out=$(curl -sS -X "$method" "$CM_URL/api/v1$path" -H "Authorization: Bearer $CM_TOKEN" -w '\n%{http_code}') || return 1
    fi
    code="${out##*$'\n'}"
    printf '%s' "${out%$'\n'*}"
    [[ "$code" =~ ^2 ]]
}

# wait_op ID TIMEOUT_SECONDS: follows an operation to the end; non-zero if it failed or timed out.
wait_op() {
    local id="$1" limit="$2" start status last="" body
    start=$(date +%s)
    while :; do
        body=$(api GET "/operations/$id") || { say "operation $id: $body"; return 1; }
        status=$(jq -r .status <<<"$body")
        stage=$(jq -r '[.stages[]? | select(.status=="running") | .name] | first // ""' <<<"$body")
        [ "$status $stage" != "$last" ] && say "operation $id: $status ${stage:+($stage)}" && last="$status $stage"
        case "$status" in
            succeeded) return 0 ;;
            failed) say "operation $id failed: $(jq -r '.error // "no error recorded"' <<<"$body")"; return 1 ;;
        esac
        [ $(( $(date +%s) - start )) -ge "$limit" ] && { say "operation $id: still $status after ${limit}s"; return 1; }
        sleep 5
    done
}

# check_url URL: answers 2xx over HTTPS with something in the body, within about two minutes.
check_url() {
    local url="$1" i body
    for i in $(seq 1 12); do
        body=$(curl -fsS --max-time 15 "$url" 2>/dev/null) && [ -n "$body" ] && { say "$url answers ($(wc -c <<<"$body" | tr -d ' ') bytes)"; return 0; }
        sleep 10
    done
    say "$url did not answer over HTTPS"
    return 1
}

# delete_site NAME: deletes the application and its data, waiting for it.
delete_site() {
    local out
    out=$(api DELETE "/sites/$1?data=1") || { say "could not delete $1: $out"; return 1; }
    wait_op "$(jq -r .operation <<<"$out")" 600
}

delete_server() {
    local out
    out=$(api DELETE "/servers/$1") || { say "could not delete server $1: $out"; return 1; }
    wait_op "$(jq -r .operation <<<"$out")" 900
}

# sweep: whatever an earlier run left, once it is old enough that it cannot be a run still going.
sweep() {
    local name epoch node
    while read -r name; do
        epoch=$(sed -E 's/^live-([0-9]+)-.*/\1/' <<<"$name")
        [ "$epoch" -lt $((NOW - SWEEP_AGE)) ] && { say "sweep: deleting leftover application $name"; delete_site "$name" || FAILED+=("sweep $name"); }
    done < <(api GET /sites | jq -r '.sites[].name | select(test("^live-[0-9]+-"))')
    while read -r node; do
        epoch=$(sed -E 's/^live-([0-9]+)-.*/\1/' <<<"$node")
        [ "$epoch" -lt $((NOW - SWEEP_AGE)) ] && { say "sweep: destroying leftover server $node"; delete_server "$node" || FAILED+=("sweep $node"); }
    done < <(api GET /servers | jq -r '.servers[].node | select(test("^live-[0-9]+-"))')
}

# cleanup: what this run made, newest first, then the server. Runs however the script ends.
cleanup() {
    local status=$? i
    trap - EXIT INT TERM
    if [ "${LIVE_KEEP:-}" = 1 ]; then
        say "LIVE_KEEP=1: left ${MADE_SITES[*]:-nothing} and ${NODE:-no server} in place. Delete them yourself, or the next run sweeps them after an hour."
    else
        say "cleaning up"
        for ((i = ${#MADE_SITES[@]} - 1; i >= 0; i--)); do delete_site "${MADE_SITES[$i]}" || FAILED+=("delete ${MADE_SITES[$i]}"); done
        [ -z "$NODE" ] && NODE=$(api GET /servers 2>/dev/null | jq -r --arg p "$RUN-" '[.servers[].node | select(startswith($p))] | first // ""')
        [ -n "$NODE" ] && { delete_server "$NODE" || FAILED+=("destroy $NODE"); }
    fi
    if [ ${#FAILED[@]} -gt 0 ]; then
        say "FAILED: ${FAILED[*]}"
        say "If a delete failed, look in the console for anything named $RUN-* (or older live-*): the next run sweeps it after an hour."
        exit 1
    fi
    say "all passed"
    exit "$status"
}

# make_app SCENARIO REPO RUNTIME: creates the application (on the run's server once there is one) and waits for it.
make_app() {
    local scenario="$1" repo="$2" runtime="$3" name server out op
    name="$RUN-$scenario"
    if [ -z "$NODE" ]; then
        server=$(jq -nc --arg r "$REGION" --arg s "$SIZE" '{mode: "new", region: $r, size: $s}')
    else
        server=$(jq -nc --arg n "$NODE" '{mode: "existing", node: $n}')
    fi
    out=$(jq -nc --arg name "$name" --arg repo "$repo" --arg runtime "$runtime" --argjson server "$server" \
        '{name: $name, repository: $repo, branch: "main", server: $server} + (if $runtime == "" then {} else {runtime: $runtime} end)')
    out=$(api POST /sites "$out") || { say "create $name refused: $out"; return 1; }
    MADE_SITES+=("$name")
    op=$(jq -r .operation <<<"$out")
    [ -z "$NODE" ] && NODE=$(jq -r '.server // ""' <<<"$out")
    say "$name: creating (operation $op, server ${NODE:-?})"
    wait_op "$op" 1500
}

site_url() { api GET "/sites/$1" | jq -r '.site.url // .url // empty'; }

scenario_static() {
    make_app static "$REPO_STATIC" static || return 1
    check_url "$(site_url "$RUN-static")"
}

scenario_go() {
    make_app go "$REPO_GO" go || return 1
    check_url "$(site_url "$RUN-go")"
}

scenario_laravel() {
    local name="$RUN-laravel" out
    make_app laravel "$REPO_LARAVEL" "" || return 1
    check_url "$(site_url "$name")" || return 1
    say "$name: deploying again, then rolling back"
    out=$(api POST "/sites/$name/deploy") && wait_op "$(jq -r .operation <<<"$out")" 900 || return 1
    out=$(api POST "/sites/$name/rollback") && wait_op "$(jq -r .operation <<<"$out")" 600 || return 1
    check_url "$(site_url "$name")"
}

main() {
    local scenarios=("$@") s
    api GET /whoami >/dev/null || { echo "The token was refused by $CM_URL" >&2; exit 2; }
    jq -e '.scopes | index("manage")' <<<"$(api GET /whoami)" >/dev/null || { echo "The token needs the manage scope" >&2; exit 2; }
    trap cleanup EXIT INT TERM
    sweep
    [ "${scenarios[0]:-}" = sweep ] && return 0
    [ ${#scenarios[@]} -eq 0 ] && scenarios=(static go laravel)
    say "run $RUN: ${scenarios[*]} on one new server ($REGION, $SIZE)"
    for s in "${scenarios[@]}"; do
        declare -F "scenario_$s" >/dev/null || { echo "No scenario $s" >&2; FAILED+=("unknown $s"); continue; }
        say "== $s"
        if scenario_"$s"; then say "== $s passed"; else say "== $s FAILED"; FAILED+=("$s"); fi
    done

    return 0
}

main "$@"

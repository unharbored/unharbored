<?php

declare(strict_types=1);

namespace App\Lib\Git;

/**
 * A push, as each host reports it. The hosts disagree on the payload and on
 * how a delivery proves where it came from, so this is the one place that
 * knows: GitHub, Gitea and Forgejo sign the body (HMAC-SHA256 with the
 * secret), GitLab sends the secret itself in a header, Bitbucket Cloud sends
 * nothing at all. The secret in the address is what every delivery needs; a
 * signature or token header that is present must also be right.
 */
final class GitPush
{
    /**
     * @param  array<string, string>  $headers  lowercase name => value
     * @param  array<string, mixed>  $payload
     * @return array{branch: ?string, sha: ?string, host: string}|null null when this is not a push to a branch, or its proof is wrong
     */
    public static function parse(array $headers, string $body, array $payload, string $secret): ?array
    {
        $signed = $headers['x-hub-signature-256'] ?? $headers['x-gitea-signature'] ?? $headers['x-forgejo-signature'] ?? $headers['x-gogs-signature'] ?? null;
        if ($signed !== null) {
            $expected = hash_hmac('sha256', $body, $secret);
            if (! hash_equals($expected, preg_replace('/^sha256=/', '', $signed) ?? '') && ! hash_equals('sha256='.$expected, $signed)) {
                return null;
            }
        }
        if (isset($headers['x-gitlab-token']) && ! hash_equals($secret, $headers['x-gitlab-token'])) {
            return null;
        }

        // GitHub, Gitea, Forgejo, Gogs and GitLab all put the ref at the top.
        if (isset($payload['ref'])) {
            $event = $headers['x-github-event'] ?? $headers['x-gitea-event'] ?? $headers['x-forgejo-event'] ?? $headers['x-gogs-event'] ?? $headers['x-gitlab-event'] ?? 'push';
            if (! in_array(strtolower($event), ['push', 'push hook'], true)) {
                return null;
            }
            if (($payload['deleted'] ?? false) || ! str_starts_with((string) $payload['ref'], 'refs/heads/')) {
                return null;
            }
            $sha = (string) ($payload['after'] ?? $payload['checkout_sha'] ?? '');

            return ['branch' => substr((string) $payload['ref'], strlen('refs/heads/')), 'sha' => preg_match('/^[0-9a-f]{40,64}$/', $sha) ? $sha : null,
                'host' => isset($headers['x-gitlab-event']) ? 'gitlab' : (isset($headers['x-gitea-event']) || isset($headers['x-forgejo-event']) ? 'gitea' : 'github')];
        }

        // Bitbucket Cloud: push.changes[].new.
        $change = $payload['push']['changes'][0]['new'] ?? null;
        if (is_array($change) && ($change['type'] ?? null) === 'branch') {
            $sha = (string) ($change['target']['hash'] ?? '');

            return ['branch' => (string) $change['name'], 'sha' => preg_match('/^[0-9a-f]{40,64}$/', $sha) ? $sha : null, 'host' => 'bitbucket'];
        }

        return null;
    }

    /**
     * For a Git server with no web hooks of its own: a post-receive hook that
     * tells the webhook address when the deployed branch moves. It sends the
     * same ref and commit a host would, so the one handler serves it.
     */
    public static function postReceiveScript(string $branch, string $url): string
    {
        // Both come from values we validated (a branch name, our own address), and are single-quoted regardless.
        if (! preg_match('#^[A-Za-z0-9._/-]+$#', $branch) || ! preg_match('#^https?://[A-Za-z0-9.:/_-]+$#', $url)) {
            throw new \InvalidArgumentException('Refusing to put an unexpected branch or address in a script.');
        }

        return <<<SH
#!/bin/sh
# Ask Unharbored to deploy when {$branch} is pushed.
while read old new ref; do
  if [ "\$ref" = "refs/heads/{$branch}" ]; then
    curl -fsS -m 10 -X POST -H 'Content-Type: application/json' \\
      -d "{\"ref\":\"\$ref\",\"after\":\"\$new\"}" '{$url}' >/dev/null || true
  fi
done
SH;
    }
}

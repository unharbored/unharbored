<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Auth\Roles;
use Illuminate\Support\Facades\Gate;
use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Invitations;
use App\Lib\Cloud\ProviderKeys;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Providers;
use App\Lib\Servers\SshKeys;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;
use App\Lib\Auth\Projects;

/**
 * Your account, and the organisation's: your password, its name, and who is in
 * it. Plain forms, like sign-in, so a password or an invitation link never
 * passes through a component.
 *
 * Changing your own password. A plain form, like sign-in: the password goes in
 * one POST and never through a component. Being signed in is enough, so the
 * current password is not asked for; the cost is that a stolen session can
 * change it. Every other session is signed out, and the change is audited.
 */
final class AccountController
{
    /** The same floor user:create enforces. */
    private const MIN_LENGTH = 12;

    public function __construct(private readonly AuditLog $audit, private readonly Invitations $invitations, private readonly SshKeys $keys, private readonly ProviderKeys $providers, private readonly Providers $registry) {}

    /** The tabs of the page, as key => label; a tab is listed only when the role can use what is on it. */
    private function tabs(): array
    {
        $tabs = ['team' => 'Team', 'people' => 'People', 'projects' => 'Projects', 'keys' => 'SSH keys', 'providers' => 'Providers', 'composer' => 'Composer', 'github' => 'GitHub', 'login' => 'Your login'];
        $needs = ['projects' => 'create', 'keys' => 'infrastructure', 'providers' => 'connections', 'composer' => 'connections', 'github' => 'connections'];

        return array_filter($tabs, fn ($label, $key) => ! isset($needs[$key]) || Gate::allows($needs[$key]), ARRAY_FILTER_USE_BOTH);
    }

    public function show(Request $request): Response
    {
        $tabs = $this->tabs();
        $tab = array_key_exists((string) $request->query('tab'), $tabs) ? (string) $request->query('tab') : 'team';
        $org = Org::id();
        $user = $request->user();

        return Inertia::render('Account', [
            'tab' => $tab,
            'tabs' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys($tabs), array_values($tabs)),
            'org' => (string) (DB::table('organizations')->where('id', $org)->value('name') ?: $org),
            'email' => $user->email,
            'userId' => $user->id,
            'myRole' => (string) Roles::roleOf($user, $org),
            // An invitation just made: shown once, with its link.
            'invite' => $request->session()->get('invite'),
            // Only the tab that is open is built: the GitHub tab asks GitHub, the providers tab reads the vault.
            'team' => fn () => $tab === 'team' ? [
                'canCreateTeam' => \App\Lib\Auth\Instance::canCreateTeam($user),
                'consoleEnabled' => \App\Lib\Console\Consoles::enabled($org),
            ] : null,
            'people' => fn () => $tab === 'people' ? $this->people() : null,
            'projects' => fn () => $tab === 'projects' ? Projects::listing($org) : null,
            'sshKeys' => fn () => $tab === 'keys' ? $this->sshKeys() : null,
            'providers' => fn () => $tab === 'providers' ? $this->providerPanels() : null,
            'composer' => fn () => $tab === 'composer' ? $this->composerScopes() : null,
            'github' => fn () => $tab === 'github' ? $this->githubAccounts() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function people(): array
    {
        return [
            'users' => User::join('memberships', 'memberships.user_id', '=', 'users.id')->where('memberships.organization_id', Org::id())->orderBy('users.id')
                ->get(['users.id', 'users.name', 'users.email', 'memberships.role'])->map(fn ($u) => $u->only(['id', 'name', 'email', 'role']))->all(),
            'allRoles' => Roles::all(),
            'roles' => Gate::allows('ownership') ? Roles::all() : array_values(array_diff(Roles::all(), [Roles::OWNER])),
            'invites' => collect($this->invitations->pending(Org::id()))->map(fn ($i) => [
                'id' => $i->id, 'email' => $i->email, 'role' => $i->role, 'by' => $i->invited_by,
                'expires' => \Illuminate\Support\Carbon::parse($i->expires_at)->diffForHumans(),
            ])->all(),
            'suggestions' => $this->peopleElsewhere()->map(fn ($u) => ['name' => $u->name, 'email' => $u->email])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sshKeys(): array
    {
        return DB::table('ssh_keys')->where('organization_id', Org::id())->where('name', 'not like', '% (removed)')->orderBy('id')->get()
            ->map(fn ($k) => [
                'id' => $k->id, 'name' => $k->name, 'type' => $k->type, 'fingerprint' => $k->fingerprint, 'by' => $k->created_by,
                'default' => (bool) $k->install_on_new_servers,
                'servers' => DB::table('server_ssh_keys')->where('ssh_key_id', $k->id)->where('state', '!=', 'removing')->count(),
            ])->all();
    }

    /**
     * Who has Composer credentials: the team, then each project, with the hosts and usernames (never passwords).
     *
     * @return array<string, mixed>
     */
    private function composerScopes(): array
    {
        $org = Org::id();
        $sites = app(\App\Lib\Sites\Sites::class);
        $projects = DB::table('projects')->where('organization_id', $org)->orderBy('id')->get(['id', 'name']);

        return [
            'scopes' => array_merge(
                [['key' => 'team', 'label' => 'The whole team', 'hosts' => $sites->sharedComposerHosts($org, null)]],
                $projects->map(fn ($p) => ['key' => (string) $p->id, 'label' => 'Project: '.$p->name, 'hosts' => $sites->sharedComposerHosts($org, (int) $p->id)])->all(),
            ),
        ];
    }

    /** The scope field: "team", or the id of one of the organisation's projects. */
    private function composerScope(Request $request): ?int
    {
        $scope = (string) $request->input('scope', 'team');
        if ($scope === 'team') {
            return null;
        }
        abort_unless(ctype_digit($scope) && DB::table('projects')->where('organization_id', Org::id())->where('id', (int) $scope)->exists(), 422);

        return (int) $scope;
    }

    public function saveComposer(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        $project = $this->composerScope($request);
        try {
            app(\App\Lib\Sites\Sites::class)->setSharedComposerCredential(Org::id(), $project, (string) $request->input('host', ''), (string) $request->input('username', ''), (string) $request->input('password', ''), $this->actor($request));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['composer' => $e->getMessage()])->withFragment('composer');
        }

        return $this->tab('composer')->with('status', 'Saved. Applications get it with their next deploy; the password is never shown again.');
    }

    public function removeComposer(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        app(\App\Lib\Sites\Sites::class)->removeSharedComposerHost(Org::id(), $this->composerScope($request), (string) $request->input('host', ''), $this->actor($request));

        return $this->tab('composer')->with('status', 'Removed. Applications stop getting it with their next deploy.');
    }

    /** @return array<string, mixed> */
    private function providerPanels(): array
    {
        $org = Org::id();
        $key = fn (array $k) => $k + ['badge' => ProviderKeys::badge($k)[0], 'badgeLabel' => ProviderKeys::badge($k)[1],
            'verified' => ! empty($k['verified_at']) ? \Illuminate\Support\Carbon::parse($k['verified_at'])->diffForHumans() : null];
        $credential = fn (string $provider) => ($c = DB::table('cloud_credentials')->where('organization_id', $org)->where('provider', $provider)->first())
            ? ['hint' => $c->key_hint, 'email' => $c->account_email] : null;
        $servers = fn (string $provider) => DB::table('resources')->where('organization_id', $org)->where('provider', $provider)->where('type', 'server')->where('state', '!=', 'deleted')->count();

        return [
            'cloud' => array_map($key, $this->providers->cloud($org)),
            'dns' => array_map($key, $this->providers->dnsList($org)),
            'keyBlocked' => $this->providers->blocked($org, 'cloud'),
            'soonDays' => ProviderKeys::SOON_DAYS,
            'aws' => $credential('aws'), 'awsServers' => $servers('aws'),
            'hetzner' => $credential('hetzner'), 'hetznerServers' => $servers('hetzner'),
            'tokenProviders' => array_map(fn (string $id) => ['id' => $id, 'label' => $this->registry->get($id)->label(), 'help' => self::TOKEN_PROVIDERS[$id]['help'], 'note' => self::TOKEN_PROVIDERS[$id]['note'],
                'field' => self::TOKEN_PROVIDERS[$id]['field'] ?? 'API token', 'chip' => self::TOKEN_PROVIDERS[$id]['chip'] ?? 'API token', 'noun' => self::TOKEN_PROVIDERS[$id]['noun'] ?? 'token',
                'setup' => isset(self::TOKEN_PROVIDERS[$id]['setup']) ? ['text' => self::TOKEN_PROVIDERS[$id]['setup'], 'needs' => self::TOKEN_PROVIDERS[$id]['needs'],
                    'command' => 'curl -fsSL '.rtrim((string) config('app.url'), '/').'/incus-host.sh | sudo bash'] : null,
                'credential' => $credential($id), 'servers' => $servers($id)], array_keys(self::TOKEN_PROVIDERS)),
            'tailscale' => $credential(\App\Lib\Servers\Tailscale::PROVIDER),
            'tailscaleServers' => DB::table('nodes')->where('organization_id', $org)->whereNotNull('tailnet')->count(),
            'tailscaleKeysUrl' => \App\Lib\Servers\Tailscale::KEYS_URL,
        ];
    }

    /** People in the user's other organisations who are not in this one yet, offered as invite suggestions. */
    private function peopleElsewhere(): \Illuminate\Support\Collection
    {
        $mine = Org::mine((int) auth()->id());
        $here = DB::table('memberships')->where('organization_id', Org::id())->pluck('user_id');
        $invited = collect($this->invitations->pending(Org::id()))->pluck('email')->map(fn ($e) => strtolower($e));

        return User::join('memberships', 'memberships.user_id', '=', 'users.id')
            ->whereIn('memberships.organization_id', $mine)->whereNotIn('users.id', $here)
            ->distinct()->orderBy('users.name')->get(['users.name', 'users.email'])
            ->reject(fn ($u) => $invited->contains(strtolower($u->email)))->values();
    }

    /** @return array{install_url: string, accounts: list<array<string, mixed>>, error: ?string}|null null when the GitHub App is not set up */
    private function githubAccounts(): ?array
    {
        if (! DB::table('github_apps')->where('organization_id', Org::id())->exists()) {
            return null;
        }
        try {
            return app(\App\Lib\GitHub\GitHubApp::class)->accounts(Org::id()) + ['error' => null];
        } catch (\Throwable $e) {
            return ['install_url' => '', 'accounts' => [], 'error' => 'GitHub would not list the accounts: '.$e->getMessage()];
        }
    }

    public function approveGithub(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        $login = $this->githubLogin($request);
        app(\App\Lib\GitHub\GitHubApp::class)->approveAccount(Org::id(), $login, $this->actor($request));
        Cache::forget('new-app:repos:'.Org::id());

        return back()->with('status', "Using the repositories of {$login}.");
    }

    public function removeGithub(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        $login = $this->githubLogin($request);
        app(\App\Lib\GitHub\GitHubApp::class)->unapproveAccount(Org::id(), $login, $this->actor($request));
        Cache::forget('new-app:repos:'.Org::id());

        return back()->with('status', "No longer using the repositories of {$login}.");
    }

    private function githubLogin(Request $request): string
    {
        return $request->validate(['login' => ['required', 'regex:/^[A-Za-z0-9-]{1,39}$/']])['login'];
    }

    public function addKey(Request $request): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate(['name' => 'required|string|max:60', 'public_key' => 'required|string|max:2000']);
        try {
            $this->keys->add(Org::id(), $data['name'], $data['public_key'], $request->boolean('install_on_new_servers'), $request->user()->email);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['public_key' => $e->getMessage()])->onlyInput('name', 'public_key');
        }

        return $this->tab('keys')->with('status', 'Key added. Install it on a server from that server\'s card, or it goes on new servers as they are built.');
    }

    public function keyDefault(Request $request, int $key): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $this->keys->setDefault(Org::id(), $key, $request->boolean('on'));

        return $this->tab('keys');
    }

    public function removeKey(Request $request, int $key): RedirectResponse
    {
        Gate::authorize('infrastructure');
        try {
            $nodes = $this->keys->remove(Org::id(), $key, $request->user()->email);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['key' => $e->getMessage()]);
        }
        foreach ($nodes as $node) {
            app(OperationRunner::class)->queueAdminAccess($node, 'console', $request->user()->email);
        }

        return $this->tab('keys')->with('status', $nodes === [] ? 'Key removed.' : 'Key removed. It is being taken off '.count($nodes).' '.(count($nodes) === 1 ? 'server' : 'servers').'; one that is offline keeps it until it reconnects.');
    }

    public function organization(Request $request): RedirectResponse
    {
        Gate::authorize('members');
        $name = $request->validate(['name' => 'required|string|max:80'])['name'];
        $name = trim($name);
        $from = (string) DB::table('organizations')->where('id', Org::id())->value('name');
        DB::table('organizations')->where('id', Org::id())->update(['name' => $name, 'updated_at' => now()]);

        $this->audit->record(Org::id(), $this->actor($request), 'organization.renamed', metadata: ['from' => $from, 'to' => $name], ipAddress: $request->ip(), userAgent: (string) $request->userAgent());

        return $this->tab('team')->with('status', 'Team renamed.');
    }

    public function consoles(Request $request): RedirectResponse
    {
        Gate::authorize('members');
        $on = $request->boolean('enabled');
        DB::table('organizations')->where('id', Org::id())->update(['console_enabled' => $on, 'updated_at' => now()]);
        $this->audit->record(Org::id(), $this->actor($request), $on ? 'console.enabled' : 'console.disabled', ipAddress: $request->ip(), userAgent: (string) $request->userAgent());

        return $this->tab('team')->with('status', $on ? 'Consoles are on.' : 'Consoles are off.');
    }

    public function invite(Request $request): RedirectResponse
    {
        Gate::authorize('members');
        $data = $request->validate(['email' => 'required|email|max:255', 'role' => 'sometimes|in:'.implode(',', Roles::all())]);
        $data['role'] ??= Roles::DEVELOPER;
        $email = $data['email'];
        // A Manager cannot make an Owner; otherwise they could take the organisation over.
        if ($data['role'] === Roles::OWNER && ! Gate::allows('ownership')) {
            return back()->withErrors(['role' => 'Only an Owner can invite an Owner.'])->onlyInput('email', 'role');
        }
        try {
            $sent = $this->invitations->invite(Org::id(), $email, $request->user()->email, $data['role']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->onlyInput('email');
        }

        $this->audit->record(Org::id(), $this->actor($request), 'user.invited', metadata: ['email' => strtolower($email), 'role' => $data['role']], ipAddress: $request->ip(), userAgent: (string) $request->userAgent());

        // The link is shown once: only its hash is kept.
        return $this->tab('people')->with('invite', ['email' => strtolower($email)] + $sent);
    }

    /** Anyone signed in can start an organisation of their own; they are its Owner. */
    public function createOrganization(Request $request): RedirectResponse
    {
        abort_unless(\App\Lib\Auth\Instance::canCreateTeam($request->user()), 403, 'Only an instance administrator may create a team here.');
        $name = trim($request->validate(['new_organization' => 'required|string|max:80'])['new_organization']);
        // The id appears in node ids, vault keys and audit chains, so it is fixed and opaque, not the name.
        $id = 'org-'.bin2hex(random_bytes(5));

        DB::transaction(function () use ($id, $name, $request) {
            DB::table('organizations')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            \App\Lib\Auth\Projects::ensureDefault($id);
            DB::table('memberships')->insert(['user_id' => $request->user()->id, 'organization_id' => $id, 'role' => Roles::OWNER, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->audit->record($id, $this->actor($request), 'organization.created', metadata: ['name' => $name], ipAddress: $request->ip(), userAgent: (string) $request->userAgent());
        $request->session()->put(Org::SESSION_KEY, $id);
        $request->session()->forget(\App\Lib\Auth\Projects::SESSION_KEY);

        return $this->tab('team')->with('status', "{$name} created. You are in it now.");
    }

    public function switchOrganization(Request $request): RedirectResponse
    {
        $id = (string) $request->input('organization');
        // Only a team the user belongs to; anything else is ignored.
        if (in_array($id, Org::mine($request->user()->id), true)) {
            $request->session()->put(Org::SESSION_KEY, $id);
            $request->session()->forget(\App\Lib\Auth\Projects::SESSION_KEY);
        }

        return redirect()->route('sites');
    }

    /** The top bar's project filter: a project of this organisation, or empty for all projects. */
    public function switchProject(Request $request): RedirectResponse
    {
        \App\Lib\Auth\Projects::choose((int) $request->input('project'));

        return redirect()->route('sites');
    }

    public function revoke(Request $request, int $invitation): RedirectResponse
    {
        Gate::authorize('members');
        $email = $this->invitations->revoke(Org::id(), $invitation);
        if ($email !== null) {
            $this->audit->record(Org::id(), $this->actor($request), 'user.invitation_revoked', metadata: ['email' => $email], ipAddress: $request->ip(), userAgent: (string) $request->userAgent());
        }

        return $this->tab('people')->with('status', 'Invitation withdrawn.');
    }

    public function changeRole(Request $request, int $user): RedirectResponse
    {
        Gate::authorize('members');
        $role = $request->validate(['role' => 'required|in:'.implode(',', Roles::all())])['role'];
        $target = User::find($user);
        $current = $target === null ? null : Roles::roleOf($target, Org::id());

        if ($target === null || $current === null) {
            return back()->withErrors(['remove' => 'That person is not in this team.']);
        }
        if ($target->id === $request->user()->id) {
            return back()->withErrors(['remove' => 'You cannot change your own role. Ask another Owner or Manager.']);
        }
        // Only an Owner touches Owners, in either direction; otherwise a Manager could take the organisation over.
        if (($current === Roles::OWNER || $role === Roles::OWNER) && ! Gate::allows('ownership')) {
            return back()->withErrors(['remove' => 'Only an Owner can promote someone to Owner or change an Owner.']);
        }
        if ($current === Roles::OWNER && $role !== Roles::OWNER
            && DB::table('memberships')->where('organization_id', Org::id())->where('role', Roles::OWNER)->count() < 2) {
            return back()->withErrors(['remove' => 'A team needs at least one Owner.']);
        }
        if ($current !== $role) {
            DB::table('memberships')->where('user_id', $target->id)->where('organization_id', Org::id())->update(['role' => $role, 'updated_at' => now()]);
            $this->audit->record(Org::id(), $this->actor($request), 'user.role_changed', metadata: ['email' => $target->email, 'from' => $current, 'to' => $role], ipAddress: $request->ip(), userAgent: (string) $request->userAgent());
        }

        return $this->tab('people')->with('status', "{$target->email} is now ".ucfirst($role).'.');
    }

    public function remove(Request $request, int $user): RedirectResponse
    {
        Gate::authorize('members');
        $target = User::find($user);
        if ($target === null || $target->id === $request->user()->id) {
            return back()->withErrors(['remove' => 'You cannot remove your own account here.']);
        }

        $role = Roles::roleOf($target, Org::id());
        if ($role === null) {
            return back()->withErrors(['remove' => 'That person is not in this team.']);
        }
        if ($role === Roles::OWNER && ! Gate::allows('ownership')) {
            return back()->withErrors(['remove' => 'Only an Owner can remove an Owner.']);
        }
        if ($role === Roles::OWNER && DB::table('memberships')->where('organization_id', Org::id())->where('role', Roles::OWNER)->count() < 2) {
            return back()->withErrors(['remove' => 'A team needs at least one Owner.']);
        }

        DB::table('memberships')->where('user_id', $target->id)->where('organization_id', Org::id())->delete();
        // Someone who belongs nowhere else loses the account; otherwise only this organisation.
        if (! DB::table('memberships')->where('user_id', $target->id)->exists()) {
            DB::table('sessions')->where('user_id', $target->id)->delete();
            $target->delete();
        }
        $this->audit->record(Org::id(), $this->actor($request), 'user.removed', metadata: ['email' => $target->email], ipAddress: $request->ip(), userAgent: (string) $request->userAgent());

        return $this->tab('people')->with('status', "{$target->email} was removed.");
    }

    /** Back to the Account page, on the section the action belongs to. */
    private function tab(string $tab, string $anchor = ''): RedirectResponse
    {
        return redirect()->to(route('account', ['tab' => $tab]).$anchor);
    }

    private function actor(Request $request): Actor
    {
        return Actor::user((string) $request->user()->id, $request->user()->email);
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => 'required|string|min:'.self::MIN_LENGTH.'|max:1000|confirmed',
        ]);
        $user = $request->user();
        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Choose a password you are not already using.']);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();

        // Sign out everywhere else; this session stays, on a fresh id.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();

        $this->audit->record(Org::id(), Actor::user((string) $user->id, $user->email), 'auth.password_changed', ipAddress: $request->ip(), userAgent: (string) $request->userAgent());

        return $this->tab('login')->with('status', 'Password changed. Every other session was signed out.');
    }

    /** Connects or replaces a provider's key. The provider checks it first; a refused key changes nothing. */
    public function connectProvider(Request $request, string $kind): RedirectResponse
    {
        Gate::authorize('connections');
        $actor = $this->actor($request);
        $expires = ['expires_at' => 'nullable|date_format:Y-m-d'];
        try {
            if ($kind === 'cloud') {
                $data = $request->validate(['token' => 'required|string|max:300'] + $expires);
                $this->providers->connectCloud(Org::id(), $data['token'], $data['expires_at'] ?? null, $actor);
            } else {
                $data = $request->validate(['domain' => 'required|string|max:2000', 'key' => 'required|string|max:300', 'secret' => 'required|string|max:300'] + $expires);
                $this->providers->connectDns(Org::id(), $data['domain'], $data['key'], $data['secret'], $data['expires_at'] ?? null, $actor);
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(["provider_{$kind}" => $e->getMessage()])->withFragment('provider-keys');
        }

        return $this->tab('providers', '#provider-keys')->with('status', 'The key was checked and saved. The earlier one, if any, is gone.');
    }

    public function providerExpiry(Request $request, string $kind): RedirectResponse
    {
        Gate::authorize('connections');
        $data = $request->validate(['expires_at' => 'nullable|date_format:Y-m-d']);
        $this->providers->setExpiry(Org::id(), $kind, $data['expires_at'] ?? null, $this->actor($request));

        return $this->tab('providers', '#provider-keys')->with('status', ($data['expires_at'] ?? null) ? 'Expiry saved.' : 'No expiry set.');
    }

    /** Changes which domains the saved DNS key serves, without asking for the key again. */
    public function dnsDomains(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        $data = $request->validate(['domains' => 'required|string|max:2000']);
        try {
            $this->providers->setDnsDomains(Org::id(), $data['domains']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['provider_dns' => $e->getMessage()])->withFragment('provider-keys');
        }

        return $this->tab('providers', '#provider-keys')->with('status', 'The domains were checked and saved.');
    }

    public function testProvider(Request $request, string $kind): RedirectResponse
    {
        Gate::authorize('connections');
        $ok = $this->providers->test(Org::id(), $kind, $this->actor($request));

        return $this->tab('providers', '#provider-keys')->with('status', $ok ? 'The provider accepted the key.' : 'The provider rejected the key. Replace it.');
    }

    public function disconnectProvider(Request $request, string $kind): RedirectResponse
    {
        Gate::authorize('connections');
        try {
            $this->providers->disconnect(Org::id(), $kind, $this->actor($request));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(["provider_{$kind}" => $e->getMessage()])->withFragment('provider-keys');
        }

        return $this->tab('providers', '#provider-keys')->with('status', 'Disconnected.');
    }

    /** Connects AWS, or a service that speaks its API at a URL. The key is checked first; a refused key changes nothing. */
    public function connectAws(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        $data = $request->validate(['access_key_id' => 'required|string|max:128', 'secret_access_key' => 'required|string|max:200', 'region' => 'nullable|string|max:30',
            'endpoint' => 'nullable|url|max:300', 'ami' => 'nullable|string|max:30']);
        try {
            $this->registry->aws()->connect(Org::id(), array_filter($data, fn ($v) => $v !== null) + ['region' => 'us-east-1'], $this->actor($request));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['provider_aws' => $e->getMessage()])->withFragment('provider-aws');
        }

        return $this->tab('providers', '#provider-aws')->with('status', 'The key was checked and saved.');
    }

    public function testAws(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        try {
            $who = $this->registry->aws()->verify(Org::id(), $this->actor($request));
        } catch (\Throwable $e) {
            return $this->tab('providers', '#provider-aws')->withErrors(['provider_aws' => 'The provider did not accept the key: '.$e->getMessage()]);
        }

        return $this->tab('providers', '#provider-aws')->with('status', 'Accepted as '.$who['Arn'].'.');
    }

    public function disconnectAws(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        if (DB::table('resources')->where('organization_id', Org::id())->where('provider', 'aws')->where('type', 'server')->where('state', '!=', 'deleted')->exists()) {
            return $this->tab('providers', '#provider-aws')->withErrors(['provider_aws' => 'Servers on AWS still need this key to be managed or destroyed. Remove them first.']);
        }
        app(\App\Lib\Cloud\CredentialStore::class)->forgetProvider(Org::id(), 'aws', $this->actor($request));

        return $this->tab('providers', '#provider-aws')->with('status', 'Disconnected.');
    }

    public function connectHetzner(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        $data = $request->validate(['token' => 'required|string|max:200']);
        try {
            $this->registry->hetzner()->connect(Org::id(), $data['token'], $this->actor($request));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['provider_hetzner' => $e->getMessage()])->withFragment('provider-hetzner');
        }

        return $this->tab('providers', '#provider-hetzner')->with('status', 'The token was checked and saved.');
    }

    public function testHetzner(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        try {
            $this->registry->hetzner()->verify(Org::id(), $this->actor($request));
        } catch (\Throwable $e) {
            return $this->tab('providers', '#provider-hetzner')->withErrors(['provider_hetzner' => 'Hetzner did not accept the token: '.$e->getMessage()]);
        }

        return $this->tab('providers', '#provider-hetzner')->with('status', 'Hetzner accepted the token.');
    }

    public function disconnectHetzner(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        if (DB::table('resources')->where('organization_id', Org::id())->where('provider', 'hetzner')->where('type', 'server')->where('state', '!=', 'deleted')->exists()) {
            return $this->tab('providers', '#provider-hetzner')->withErrors(['provider_hetzner' => 'Servers on Hetzner still need this token to be managed or destroyed. Remove them first.']);
        }
        app(\App\Lib\Cloud\CredentialStore::class)->forgetProvider(Org::id(), 'hetzner', $this->actor($request));

        return $this->tab('providers', '#provider-hetzner')->with('status', 'Disconnected.');
    }

    /** The server providers whose only credential is one API token: one set of actions serves them all. */
    private const TOKEN_PROVIDERS = [
        'vultr' => ['note' => 'Cloud servers, with local databases.', 'help' => 'An API key from Account, API. If it is limited to listed addresses, add this control plane\'s. It is checked first, and the old one stays until then.'],
        'linode' => ['note' => 'Cloud servers, with local databases.', 'help' => 'A personal access token with read and write on Linodes, from Profile, API Tokens. It is checked first, and the old one stays until then.'],
        'incus' => ['note' => 'Your own bare-metal machines, split into servers. Each server gets a public IPv6 address; visitors on IPv4 arrive through the host.',
            'help' => 'It works once and expires in an hour. Unharbored makes its own key, and the host trusts that key for its project only. Nothing secret is kept from this string.',
            'field' => 'Paste the connection string it prints', 'chip' => 'Client certificate', 'noun' => 'connection',
            'saved' => 'Connected. The host trusts Unharbored\'s key for its project, and the connection string cannot be used again.',
            'setup' => 'Simplest: let Unharbored set the host up. On the Servers page, create a new server or connect your own machine, and choose "An Incus host". Or, without Unharbored\'s agent on the machine, run this as root on a fresh Ubuntu 24.04 or 26.04 machine. It installs Incus, prepares storage and networking, and prints a connection string. Safe to run again.',
            'needs' => 'Needs: 2+ cores, 4 GB+ memory, 30 GB+ free disk, one public IPv4 and an IPv6 /64. KVM is optional; without it servers run as containers.'],
    ];

    private function tokenProvider(string $provider): \App\Lib\Providers\TokenApiProvider|\App\Lib\Providers\Incus\IncusProvider
    {
        abort_unless(isset(self::TOKEN_PROVIDERS[$provider]), 404);

        return $this->registry->get($provider);
    }

    public function connectToken(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('connections');
        $data = $request->validate(['token' => 'required|string|max:4000']);
        try {
            $this->tokenProvider($provider)->connect(Org::id(), $data['token'], $this->actor($request));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(["provider_{$provider}" => $e->getMessage()])->withFragment("provider-{$provider}");
        }

        return $this->tab('providers', "#provider-{$provider}")->with('status', self::TOKEN_PROVIDERS[$provider]['saved'] ?? 'The token was checked and saved.');
    }

    public function testToken(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('connections');
        $label = $this->tokenProvider($provider)->label();
        try {
            $this->tokenProvider($provider)->verify(Org::id(), $this->actor($request));
        } catch (\Throwable $e) {
            return $this->tab('providers', "#provider-{$provider}")->withErrors(["provider_{$provider}" => $e instanceof InvalidArgumentException ? $e->getMessage() : "{$label} did not accept the token: ".$e->getMessage()]);
        }

        return $this->tab('providers', "#provider-{$provider}")->with('status', "{$label} accepted the ".(self::TOKEN_PROVIDERS[$provider]['noun'] ?? 'token').'.');
    }

    public function disconnectToken(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('connections');
        $label = $this->tokenProvider($provider)->label();
        if ($provider === \App\Lib\Providers\Incus\IncusProvider::PROVIDER) {
            // The IPv4 ingress is part of the host, so it goes with the connection; servers on the host do not.
            if (($host = \App\Lib\Providers\Incus\IncusHost::node(Org::id())) !== null) {
                return $this->tab('providers', "#provider-{$provider}")->withErrors(["provider_{$provider}" => 'Unharbored manages this Incus host as '.(strstr($host->node_id, '.', true) ?: $host->node_id).'. Remove it from the Servers page instead.']);
            }
            try {
                app(\App\Lib\Providers\Incus\IncusHost::class)->teardown(Org::id(), null, static function (): void {}, (string) $request->user()->email);
            } catch (InvalidArgumentException $e) {
                return $this->tab('providers', "#provider-{$provider}")->withErrors(["provider_{$provider}" => $e->getMessage()]);
            }

            return $this->tab('providers', "#provider-{$provider}")->with('status', 'Disconnected. The IPv4 ingress is destroyed; Incus stays installed on the machine.');
        }
        if (DB::table('resources')->where('organization_id', Org::id())->where('provider', $provider)->where('type', 'server')->where('state', '!=', 'deleted')->exists()) {
            return $this->tab('providers', "#provider-{$provider}")->withErrors(["provider_{$provider}" => "Servers on {$label} still need this ".(self::TOKEN_PROVIDERS[$provider]['noun'] ?? 'token').' to be managed or destroyed. Remove them first.']);
        }
        app(\App\Lib\Cloud\CredentialStore::class)->forgetProvider(Org::id(), $provider, $this->actor($request));

        return $this->tab('providers', "#provider-{$provider}")->with('status', 'Disconnected.');
    }

    public function connectTailscale(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        $key = trim($request->validate(['authkey' => 'required|string|max:300'])['authkey']);
        if (! \App\Lib\Servers\Tailscale::validKey($key)) {
            return back()->withErrors(['provider_tailscale' => 'That is not a Tailscale auth key. It starts with tskey-auth-. An OAuth client secret (tskey-client-) is not accepted yet.'])->withFragment('provider-tailscale');
        }
        app(\App\Lib\Cloud\CredentialStore::class)->connectSecret(Org::id(), \App\Lib\Servers\Tailscale::PROVIDER, ['authkey' => $key],
            substr($key, 0, 14).'…'.substr($key, -4), 'auth_key', $this->actor($request));

        return $this->tab('providers', '#provider-tailscale')->with('status', 'The auth key was saved. It is never shown again.');
    }

    public function disconnectTailscale(Request $request): RedirectResponse
    {
        Gate::authorize('connections');
        app(\App\Lib\Cloud\CredentialStore::class)->forgetProvider(Org::id(), \App\Lib\Servers\Tailscale::PROVIDER, $this->actor($request));

        return $this->tab('providers', '#provider-tailscale')->with('status', 'The auth key was removed. Servers already on the tailnet stay on it.');
    }
}

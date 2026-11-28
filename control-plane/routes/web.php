<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\GitHubController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

// Sign in: a password. Plain forms, no components.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'password']);
});
// Joining by invitation: the link is the credential, so it is single use,
// expires, and is throttled.
Route::middleware('guest')->group(function () {
    Route::get('/invite/{token}', [InviteController::class, 'show'])->where('token', '[a-f0-9]{48}')->name('invite');
    Route::post('/invite/{token}', [InviteController::class, 'accept'])->where('token', '[a-f0-9]{48}')->middleware('throttle:10,1');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'member'])->group(function () {
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::post('/organization', [AccountController::class, 'createOrganization'])->name('organization.create');
    Route::post('/account/projects', [\App\Http\Controllers\ProjectsController::class, 'store'])->name('projects.store');
    Route::patch('/account/projects/{id}', [\App\Http\Controllers\ProjectsController::class, 'update'])->whereNumber('id')->name('projects.update');
    Route::delete('/account/projects/{id}', [\App\Http\Controllers\ProjectsController::class, 'destroy'])->whereNumber('id')->name('projects.destroy');
    Route::post('/project/switch', [AccountController::class, 'switchProject'])->name('project.switch');
    Route::post('/organization/switch', [AccountController::class, 'switchOrganization'])->name('organization.switch');
    Route::post('/account/password', [AccountController::class, 'password'])->name('account.password');
    Route::post('/account/consoles', [AccountController::class, 'consoles'])->name('account.consoles');
    Route::post('/account/organization', [AccountController::class, 'organization'])->name('account.organization');
    Route::post('/account/invitations', [AccountController::class, 'invite'])->name('account.invite');
    Route::delete('/account/invitations/{invitation}', [AccountController::class, 'revoke'])->name('account.revoke');
    Route::post('/account/users/{user}/role', [AccountController::class, 'changeRole'])->name('account.role');
    Route::delete('/account/users/{user}', [AccountController::class, 'remove'])->name('account.remove');
    Route::post('/account/ssh-keys', [AccountController::class, 'addKey'])->name('account.keys.add');
    Route::post('/account/ssh-keys/{key}/default', [AccountController::class, 'keyDefault'])->name('account.keys.default');
    Route::delete('/account/ssh-keys/{key}', [AccountController::class, 'removeKey'])->name('account.keys.remove');
    Route::get('/servers/connect', [\App\Http\Controllers\ServerConnectController::class, 'show'])->name('servers.connect');
    Route::post('/servers/connect', [\App\Http\Controllers\ServerConnectController::class, 'store'])->name('servers.connect.store');
    Route::post('/account/github/accounts', [AccountController::class, 'approveGithub'])->name('account.github.approve');
    Route::delete('/account/github/accounts', [AccountController::class, 'removeGithub'])->name('account.github.remove');
    Route::post('/account/composer', [AccountController::class, 'saveComposer'])->name('account.composer.save');
    Route::delete('/account/composer', [AccountController::class, 'removeComposer'])->name('account.composer.remove');
    Route::post('/account/aws', [AccountController::class, 'connectAws'])->name('account.aws.connect');
    Route::post('/account/aws/test', [AccountController::class, 'testAws'])->name('account.aws.test');
    Route::delete('/account/aws', [AccountController::class, 'disconnectAws'])->name('account.aws.disconnect');
    Route::post('/account/hetzner', [AccountController::class, 'connectHetzner'])->name('account.hetzner.connect');
    Route::post('/account/hetzner/test', [AccountController::class, 'testHetzner'])->name('account.hetzner.test');
    Route::delete('/account/hetzner', [AccountController::class, 'disconnectHetzner'])->name('account.hetzner.disconnect');
    Route::post('/account/token/{provider}', [AccountController::class, 'connectToken'])->name('account.token.connect');
    Route::post('/account/token/{provider}/test', [AccountController::class, 'testToken'])->name('account.token.test');
    Route::delete('/account/token/{provider}', [AccountController::class, 'disconnectToken'])->name('account.token.disconnect');
    Route::post('/account/tailscale', [AccountController::class, 'connectTailscale'])->name('account.tailscale.connect');
    Route::delete('/account/tailscale', [AccountController::class, 'disconnectTailscale'])->name('account.tailscale.disconnect');
    Route::post('/account/providers/{kind}', [AccountController::class, 'connectProvider'])->where('kind', 'cloud|dns')->name('account.providers.connect');
    Route::post('/account/providers/{kind}/expiry', [AccountController::class, 'providerExpiry'])->where('kind', 'cloud|dns')->name('account.providers.expiry');
    Route::post('/account/providers/{kind}/test', [AccountController::class, 'testProvider'])->where('kind', 'cloud|dns')->name('account.providers.test');
    Route::delete('/account/providers/{kind}', [AccountController::class, 'disconnectProvider'])->where('kind', 'cloud|dns')->name('account.providers.disconnect');
});

// The colour theme, kept in a cookie so the layout renders it server-side
// and the page is drawn in the right theme from the first paint. No cookie
// means "follow the system", which the stylesheet handles.
Route::post('/theme', function (Illuminate\Http\Request $request) {
    $theme = $request->validate(['theme' => 'required|in:light,dark'])['theme'];

    return redirect()->back()->withCookie(cookie()->forever('theme', $theme));
})->name('theme');

// The console. Each page is a layout around one component; the components
// fetch their own data through server methods, which also require the
// session.
Route::middleware(['auth', 'member', 'site-active'])->group(function () {
    Route::redirect('/', '/sites');
    // ?project=<slug> sets the project filter (an unknown slug is ignored).
    Route::get('/sites', [\App\Http\Controllers\SitesController::class, 'index'])->name('sites');
    Route::post('/sites', [\App\Http\Controllers\SitesController::class, 'store'])->name('sites.store');
    Route::get('/apps/new', [\App\Http\Controllers\NewAppController::class, 'show'])->name('apps.new');
    Route::get('/apps/new/options', [\App\Http\Controllers\NewAppController::class, 'optionsFor'])->name('apps.new.options');
    Route::get('/apps/new/defaults', [\App\Http\Controllers\NewAppController::class, 'defaults'])->name('apps.new.defaults');
    Route::get('/apps/new/sizes', [\App\Http\Controllers\NewAppController::class, 'sizes'])->name('apps.new.sizes');
    Route::post('/apps/new/inspect', [\App\Http\Controllers\NewAppController::class, 'inspect'])->name('apps.new.inspect');
    Route::post('/apps/new/branches', [\App\Http\Controllers\NewAppController::class, 'branches'])->name('apps.new.branches');
    Route::post('/apps/new/apps', [\App\Http\Controllers\NewAppController::class, 'apps'])->name('apps.new.apps');
    Route::post('/apps/new/detected', [\App\Http\Controllers\NewAppController::class, 'detected'])->name('apps.new.detected');
    Route::post('/apps/new/source', [\App\Http\Controllers\NewAppController::class, 'source'])->name('apps.new.source');
    Route::post('/apps/new/check', [\App\Http\Controllers\NewAppController::class, 'check'])->name('apps.new.check');
    Route::post('/apps/new/create', [\App\Http\Controllers\NewAppController::class, 'create'])->name('apps.new.create');
    // Applications from a Dockerfile or a Docker image (docs/specs/templates.md, step 6).
    Route::get('/apps/container', [\App\Http\Controllers\ContainerAppNewController::class, 'show'])->name('apps.container');
    Route::get('/apps/container/options', [\App\Http\Controllers\ContainerAppNewController::class, 'optionsFor'])->name('apps.container.options');
    Route::post('/apps/container/inspect', [\App\Http\Controllers\ContainerAppNewController::class, 'inspect'])->name('apps.container.inspect');
    Route::post('/apps/container/branches', [\App\Http\Controllers\ContainerAppNewController::class, 'branches'])->name('apps.container.branches');
    Route::post('/apps/container/detection', [\App\Http\Controllers\ContainerAppNewController::class, 'detection'])->name('apps.container.detection');
    Route::post('/apps/container/check', [\App\Http\Controllers\ContainerAppNewController::class, 'check'])->name('apps.container.check');
    Route::post('/apps/container/create', [\App\Http\Controllers\ContainerAppNewController::class, 'create'])->name('apps.container.create');
    Route::get('/site/container', [\App\Http\Controllers\SiteContainerController::class, 'show'])->name('site.container');
    Route::post('/site/container/settings', [\App\Http\Controllers\SiteContainerController::class, 'saveSettings'])->name('site.container.settings');
    Route::post('/site/container/registry', [\App\Http\Controllers\SiteContainerController::class, 'saveRegistry'])->name('site.container.registry');
    Route::post('/site/container/check', [\App\Http\Controllers\SiteContainerController::class, 'checkUpdate'])->name('site.container.check');
    Route::post('/site/container/update', [\App\Http\Controllers\SiteContainerController::class, 'confirmUpdate'])->name('site.container.update');
    // Template services (docs/specs/templates.md): the list follows the project filter like Applications.
    Route::get('/services', [\App\Http\Controllers\ServicesController::class, 'index'])->name('services');
    Route::get('/services/new', [\App\Http\Controllers\ServiceNewController::class, 'show'])->name('services.new');
    Route::post('/services/new/servers', [\App\Http\Controllers\ServiceNewController::class, 'serversFor'])->name('services.new.servers');
    Route::post('/services/new/check', [\App\Http\Controllers\ServiceNewController::class, 'check'])->name('services.new.check');
    Route::post('/services/new/create', [\App\Http\Controllers\ServiceNewController::class, 'create'])->name('services.new.create');
    Route::get('/templates', [\App\Http\Controllers\ServicesController::class, 'templates'])->name('templates');
    Route::get('/service', [\App\Http\Controllers\ServiceController::class, 'show'])->name('service');
    Route::post('/service/{service}/act', [\App\Http\Controllers\ServiceController::class, 'act'])->whereNumber('service')->name('service.act');
    Route::post('/service/{service}/backup', [\App\Http\Controllers\ServiceController::class, 'backUp'])->whereNumber('service')->name('service.backup');
    Route::post('/service/{service}/update', [\App\Http\Controllers\ServiceController::class, 'update'])->whereNumber('service')->name('service.update');
    Route::post('/service/{service}/restore', [\App\Http\Controllers\ServiceController::class, 'restore'])->whereNumber('service')->name('service.restore');
    Route::post('/service/{service}/reveal', [\App\Http\Controllers\ServiceController::class, 'reveal'])->whereNumber('service')->name('service.reveal');
    Route::post('/service/{service}/remove', [\App\Http\Controllers\ServiceController::class, 'remove'])->whereNumber('service')->name('service.remove');
    Route::get('/site', [\App\Http\Controllers\SiteController::class, 'show'])->name('site');
    Route::post('/site/deploy', [\App\Http\Controllers\SiteController::class, 'deploy'])->name('site.deploy');
    Route::post('/site/rollback', [\App\Http\Controllers\SiteController::class, 'rollback'])->name('site.rollback');
    Route::post('/site/certificate/check', [\App\Http\Controllers\SiteController::class, 'certificateCheck'])->name('site.certificate.check');
    Route::post('/site/certificate/renew', [\App\Http\Controllers\SiteController::class, 'certificateRenew'])->name('site.certificate.renew');
    Route::post('/site/certificate/auto-renew', [\App\Http\Controllers\SiteController::class, 'certificateAutoRenew'])->name('site.certificate.auto-renew');
    Route::post('/site/certificate/upload', [\App\Http\Controllers\SiteController::class, 'certificateUpload'])->name('site.certificate.upload');
    Route::post('/site/certificate/revert', [\App\Http\Controllers\SiteController::class, 'certificateRevert'])->name('site.certificate.revert');
    Route::get('/operation', [\App\Http\Controllers\OperationController::class, 'show'])->name('operation');
    Route::get('/operation/log', [\App\Http\Controllers\OperationController::class, 'log'])->name('operation.log');
    Route::post('/operation/retry', [\App\Http\Controllers\OperationController::class, 'retry'])->name('operation.retry');
    require __DIR__.'/site-settings.php';
    Route::get('/site/deployments', [\App\Http\Controllers\SiteDeploymentsController::class, 'show'])->name('site.deployments');
    Route::get('/site/logs', [\App\Http\Controllers\LogsController::class, 'index'])->name('site.logs');
    Route::post('/site/logs/clear', [\App\Http\Controllers\LogsController::class, 'clear'])->name('site.logs.clear');
    Route::get('/site/console', [\App\Http\Controllers\ConsoleController::class, 'show'])->name('site.console');
    Route::post('/console/open', [\App\Http\Controllers\ConsoleController::class, 'open'])->middleware('throttle:30,1')->name('console.open');
    Route::post('/console/{session}/start', [\App\Http\Controllers\ConsoleController::class, 'start'])->middleware('throttle:30,1')->name('console.start');
    Route::post('/console/{session}/input', [\App\Http\Controllers\ConsoleController::class, 'input'])->middleware('throttle:1200,1')->name('console.input');
    Route::post('/console/{session}/resize', [\App\Http\Controllers\ConsoleController::class, 'resize'])->middleware('throttle:120,1')->name('console.resize');
    Route::post('/console/{session}/close', [\App\Http\Controllers\ConsoleController::class, 'close'])->name('console.close');
    // Servers (Inertia). The node id has a dot in it, so it is a path parameter, not a segment.
    Route::get('/servers', [\App\Http\Controllers\ServersController::class, 'index'])->name('servers');
    Route::get('/servers/pool/{site}', [\App\Http\Controllers\ServersController::class, 'pool'])->whereNumber('site')->name('servers.pool');
    Route::get('/servers/{node}', [\App\Http\Controllers\ServersController::class, 'show'])->name('servers.show');
    Route::post('/servers', [\App\Http\Controllers\ServersController::class, 'provision'])->name('servers.provision');
    Route::post('/servers/own', [\App\Http\Controllers\ServersController::class, 'own'])->name('servers.own');
    Route::post('/servers/droplets/{resource}/destroy', [\App\Http\Controllers\ServersController::class, 'destroyDroplet'])->whereNumber('resource')->name('servers.droplet.destroy');
    Route::post('/servers/archives/{archive}/delete', [\App\Http\Controllers\ServersController::class, 'deleteArchive'])->whereNumber('archive')->name('servers.archive.delete');
    Route::post('/servers/{node}/disconnect', [\App\Http\Controllers\ServersController::class, 'disconnect'])->name('servers.disconnect');
    Route::post('/servers/{node}/archive', [\App\Http\Controllers\ServersController::class, 'archive'])->name('servers.archive');
    Route::post('/servers/{node}/restore', [\App\Http\Controllers\ServersController::class, 'restore'])->name('servers.restore');
    Route::post('/servers/{node}/remove-stale', [\App\Http\Controllers\ServersController::class, 'removeStale'])->name('servers.stale');
    Route::post('/servers/{node}/delete', [\App\Http\Controllers\ServersController::class, 'delete'])->name('servers.delete');
    Route::post('/servers/{node}/destroy', [\App\Http\Controllers\ServersController::class, 'destroy'])->name('servers.destroy');
    Route::post('/servers/{node}/repair', [\App\Http\Controllers\ServersController::class, 'repair'])->name('servers.repair');
    Route::post('/servers/{node}/upgrade-agent', [\App\Http\Controllers\ServersController::class, 'upgradeAgent'])->name('servers.upgrade');
    Route::post('/servers/{node}/sudo/reveal', [\App\Http\Controllers\ServerAccessController::class, 'reveal'])->name('servers.sudo.reveal');
    Route::post('/servers/{node}/sudo/rotate', [\App\Http\Controllers\ServerAccessController::class, 'rotate'])->name('servers.sudo.rotate');
    Route::post('/servers/{node}/harden', [\App\Http\Controllers\ServerAccessController::class, 'harden'])->name('servers.harden');
    Route::post('/servers/{node}/firewall', [\App\Http\Controllers\ServerAccessController::class, 'firewall'])->name('servers.firewall');
    Route::post('/servers/{node}/tailscale/join', [\App\Http\Controllers\ServerAccessController::class, 'tailscaleJoin'])->name('servers.tailscale.join');
    Route::post('/servers/{node}/tailscale/leave', [\App\Http\Controllers\ServerAccessController::class, 'tailscaleLeave'])->name('servers.tailscale.leave');
    Route::post('/servers/{node}/keys/{key}', [\App\Http\Controllers\ServerAccessController::class, 'key'])->whereNumber('key')->name('servers.key');
    Route::get('/servers/{node}/php/{version}', [\App\Http\Controllers\ServerStackController::class, 'phpSettings'])->name('servers.php.settings');
    Route::post('/servers/{node}/php/{version}/settings', [\App\Http\Controllers\ServerStackController::class, 'applyPhpSettings'])->name('servers.php.apply');
    Route::post('/servers/{node}/php/{version}/install', [\App\Http\Controllers\ServerStackController::class, 'installPhp'])->name('servers.php.install');
    Route::post('/servers/{node}/php/{version}/update', [\App\Http\Controllers\ServerStackController::class, 'updatePhp'])->name('servers.php.update');
    Route::post('/servers/{node}/services/{service}', [\App\Http\Controllers\ServerStackController::class, 'installService'])->name('servers.service.install');
    Route::get('/server/containers', [\App\Http\Controllers\ServerContainersController::class, 'show'])->name('server.containers');
    Route::post('/server/containers/prepare', [\App\Http\Controllers\ServerContainersController::class, 'prepare'])->name('server.containers.prepare');
    Route::post('/server/containers/cleanup', [\App\Http\Controllers\ServerContainersController::class, 'cleanup'])->name('server.containers.cleanup');
    Route::post('/server/containers/refresh', [\App\Http\Controllers\ServerContainersController::class, 'refresh'])->name('server.containers.refresh');
    Route::get('/databases', [\App\Http\Controllers\DatabasesController::class, 'index'])->name('databases');
    Route::post('/databases', [\App\Http\Controllers\DatabasesController::class, 'store'])->name('databases.store');
    Route::get('/databases/discover', [\App\Http\Controllers\DatabasesController::class, 'discover'])->name('databases.discover');
    Route::post('/databases/adopt', [\App\Http\Controllers\DatabasesController::class, 'adopt'])->name('databases.adopt');
    Route::delete('/databases/{cluster}', [\App\Http\Controllers\DatabasesController::class, 'destroy'])->whereNumber('cluster')->name('databases.destroy');
    Route::post('/databases/{cluster}/archive', [\App\Http\Controllers\DatabasesController::class, 'archive'])->whereNumber('cluster')->name('databases.archive');
    Route::post('/databases/{cluster}/restore', [\App\Http\Controllers\DatabasesController::class, 'restore'])->whereNumber('cluster')->name('databases.restore');
    Route::post('/databases/{cluster}/certificate', [\App\Http\Controllers\DatabasesController::class, 'reissue'])->whereNumber('cluster')->name('databases.reissue');
    Route::get('/audit', [\App\Http\Controllers\AuditController::class, 'index'])->name('audit');
    Route::get('/alerts', [\App\Http\Controllers\AlertsController::class, 'index'])->name('alerts');
    Route::post('/alerts/webhook', [\App\Http\Controllers\AlertsController::class, 'saveWebhook'])->name('alerts.webhook');
    Route::post('/alerts/email', [\App\Http\Controllers\AlertsController::class, 'saveEmail'])->name('alerts.email');
    Route::post('/alerts/rules', [\App\Http\Controllers\AlertsController::class, 'saveRules'])->name('alerts.rules');
    Route::post('/alerts/test', [\App\Http\Controllers\AlertsController::class, 'test'])->name('alerts.test');
    Route::get('/logs', [\App\Http\Controllers\LogsController::class, 'index'])->name('logs');
    Route::get('/tokens', [\App\Http\Controllers\TokensController::class, 'index'])->name('tokens');
    Route::post('/tokens', [\App\Http\Controllers\TokensController::class, 'store'])->name('tokens.store');
    Route::delete('/tokens/{id}', [\App\Http\Controllers\TokensController::class, 'revoke'])->whereNumber('id')->name('tokens.revoke');
});

// The GitHub App manifest flow. Guarded by a one-time state from github:setup.
Route::get('/github/setup', [GitHubController::class, 'setup'])->name('github.setup');
Route::get('/github/callback', [GitHubController::class, 'callback'])->name('github.callback');

// A scheduled job or the Laravel scheduler reporting that it ran. Public, since a
// cron line has no session; the 40-hex token in the URL is the credential.
Route::match(['get', 'post'], '/heartbeat/{token}', fn (string $token) => \App\Lib\Sites\Heartbeats::ping($token)
    ? response()->noContent() : abort(404))->where('token', '[0-9a-f]{40}')->middleware('throttle:120,1')->name('heartbeat');

// The Incus host setup script the Account page tells people to run. Public and the same for everyone: it holds
// nothing secret, and the connection string it prints is made on the host.
Route::get('/incus-host.sh', fn () => response(file_get_contents(resource_path('scripts/incus-host.sh')), 200,
    ['Content-Type' => 'text/x-shellscript; charset=utf-8', 'Cache-Control' => 'no-store']))->middleware('throttle:30,1')->name('incus-host');

// Bring your own server: the one-time install script behind the command the
// console gives out. Public, since the server running it has no session; the
// 48-hex secret in the URL is the credential, used once, for 15 minutes.
Route::get('/install/{secret}', function (string $secret, \Illuminate\Http\Request $request, \App\Lib\Servers\ServerInstall $install) {
    $script = $install->script($secret, (string) $request->ip());

    return $script === null
        ? response("This install link has expired or was already used. Add the server again in the console for a new one.\n", 410, ['Content-Type' => 'text/plain'])
        : response($script, 200, ['Content-Type' => 'text/x-shellscript', 'Cache-Control' => 'no-store']);
})->where('secret', '[0-9a-f]{48}')->middleware('throttle:30,1')->name('install');

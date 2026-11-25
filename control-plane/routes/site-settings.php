<?php

use App\Http\Controllers\SiteSettings\SiteBuildController;
use App\Http\Controllers\SiteSettings\SiteDatabaseController;
use App\Http\Controllers\SiteSettings\SiteEnvController;
use App\Http\Controllers\SiteSettings\SiteLifecycleController;
use App\Http\Controllers\SiteSettings\SitePreviewsController;
use App\Http\Controllers\SiteSettings\SiteProcessesController;
use App\Http\Controllers\SiteSettings\SiteSchedulerController;
use App\Http\Controllers\SiteSettings\SiteScriptController;
use App\Http\Controllers\SiteSettings\SiteServersController;
use App\Http\Controllers\SiteSettings\SiteSettingsController;
use App\Http\Controllers\SiteSettings\SiteSourceController;
use App\Http\Controllers\SiteSettings\SiteWebController;
use Illuminate\Support\Facades\Route;

/*
 * An application's Settings: the page, and the action each control sends.
 * Included from routes/web.php inside the signed-in, member-only group.
 * Each action checks its own permission (the one the control needs), and finds the
 * application in the current team or answers 404.
 */
Route::get('/site/settings', [SiteSettingsController::class, 'show'])->name('site.settings');

Route::prefix('/site/settings/{site}')->whereNumber('site')->name('site.settings.')->group(function () {
    // Variables
    Route::post('env/reveal', [SiteEnvController::class, 'reveal'])->name('env.reveal');
    Route::post('env/injected', [SiteEnvController::class, 'revealInjected'])->name('env.injected');
    Route::post('env', [SiteEnvController::class, 'save'])->name('env.save');
    Route::post('env/apply', [SiteEnvController::class, 'apply'])->name('env.apply');
    Route::post('env/restore', [SiteEnvController::class, 'restore'])->name('env.restore');
    Route::post('env/override/remove', [SiteEnvController::class, 'removeOverride'])->name('env.override.remove');
    Route::post('env/copies/clean', [SiteEnvController::class, 'cleanCopies'])->name('env.copies.clean');

    // Domain, HTTPS, web server, password protection
    Route::post('domain', [SiteWebController::class, 'moveDomain'])->name('domain');
    Route::post('domain/free', [SiteWebController::class, 'freeAddress'])->name('domain.free');
    Route::post('https', [SiteWebController::class, 'https'])->name('https');
    Route::post('web', [SiteWebController::class, 'apply'])->name('web');
    Route::post('auth', [SiteWebController::class, 'saveAuth'])->name('auth.save');
    Route::post('auth/remove', [SiteWebController::class, 'removeAuth'])->name('auth.remove');

    // Database, backups, copies, uploads
    Route::post('database/connection', [SiteDatabaseController::class, 'connection'])->name('database.connection');
    Route::post('database/local', [SiteDatabaseController::class, 'local'])->name('database.local');
    Route::post('database/attach', [SiteDatabaseController::class, 'attach'])->name('database.attach');
    Route::post('database/detach', [SiteDatabaseController::class, 'detach'])->name('database.detach');
    Route::post('storage', [SiteDatabaseController::class, 'storage'])->name('storage');
    Route::post('backup/setting', [SiteDatabaseController::class, 'backups'])->name('backup.setting');
    Route::post('backup', [SiteDatabaseController::class, 'backUp'])->name('backup');
    Route::post('backup/restore', [SiteDatabaseController::class, 'restore'])->name('backup.restore');
    Route::post('backup/restore-cluster', [SiteDatabaseController::class, 'restoreFromCluster'])->name('backup.restore-cluster');
    Route::post('clones/sql', [SiteDatabaseController::class, 'saveCloneSql'])->name('clones.sql');
    Route::post('clones', [SiteDatabaseController::class, 'clone'])->name('clones.create');
    Route::post('clones/{clone}/reveal', [SiteDatabaseController::class, 'revealClone'])->whereNumber('clone')->name('clones.reveal');
    Route::post('clones/{clone}/drop', [SiteDatabaseController::class, 'dropClone'])->whereNumber('clone')->name('clones.drop');

    // Servers and scaling
    Route::post('servers/add', [SiteServersController::class, 'add'])->name('servers.add');
    Route::post('servers/new', [SiteServersController::class, 'create'])->name('servers.new');
    Route::post('servers/remove', [SiteServersController::class, 'remove'])->name('servers.remove');
    Route::post('balancer', [SiteServersController::class, 'balancer'])->name('balancer');
    Route::post('pool', [SiteServersController::class, 'pool'])->name('pool');
    Route::post('pool/remove', [SiteServersController::class, 'removePool'])->name('pool.remove');
    Route::post('pool/resume', [SiteServersController::class, 'resume'])->name('pool.resume');
    Route::post('windows', [SiteServersController::class, 'addWindow'])->name('windows.add');
    Route::post('windows/remove', [SiteServersController::class, 'removeWindow'])->name('windows.remove');

    // Workers and processes
    Route::post('workers', [SiteProcessesController::class, 'workers'])->name('workers');
    Route::post('workers/scaling', [SiteProcessesController::class, 'scaling'])->name('workers.scaling');
    Route::post('workers/fixed', [SiteProcessesController::class, 'fixed'])->name('workers.fixed');
    Route::post('reverb', [SiteProcessesController::class, 'reverb'])->name('reverb');
    Route::post('daemons', [SiteProcessesController::class, 'addDaemon'])->name('daemons.add');
    Route::post('workers/runs-on', [SiteProcessesController::class, 'workersOn'])->name('workers.runs_on');
    Route::post('daemons/preset', [SiteProcessesController::class, 'addPreset'])->name('daemons.preset');
    Route::post('daemons/remove', [SiteProcessesController::class, 'removeDaemon'])->name('daemons.remove');

    // Scheduler and custom jobs
    Route::post('scheduler', [SiteSchedulerController::class, 'toggle'])->name('scheduler');
    Route::post('scheduler/monitor', [SiteSchedulerController::class, 'monitor'])->name('scheduler.monitor');
    Route::post('scheduler/tasks', [SiteSchedulerController::class, 'tasks'])->name('scheduler.tasks');
    Route::post('jobs', [SiteSchedulerController::class, 'saveJob'])->name('jobs.save');
    Route::post('jobs/{job}/pause', [SiteSchedulerController::class, 'pauseJob'])->whereNumber('job')->name('jobs.pause');
    Route::post('jobs/{job}/drop', [SiteSchedulerController::class, 'dropJob'])->whereNumber('job')->name('jobs.drop');
    Route::post('jobs/{job}/output', [SiteSchedulerController::class, 'output'])->whereNumber('job')->name('jobs.output');
    Route::post('jobs/{job}/run', [SiteSchedulerController::class, 'run'])->whereNumber('job')->name('jobs.run');
    Route::post('jobs/{job}/next', [SiteSchedulerController::class, 'next'])->whereNumber('job')->name('jobs.next');

    // Server setup script
    Route::post('script/versions/{version}', [SiteScriptController::class, 'version'])->whereNumber('version')->name('script.version');
    Route::post('script', [SiteScriptController::class, 'store'])->name('script.save');

    // Type and build, PHP version
    Route::post('runtime', [SiteBuildController::class, 'runtime'])->name('runtime');
    Route::post('build-script', [SiteBuildController::class, 'buildScript'])->name('build-script');
    Route::post('composer-auth', [SiteBuildController::class, 'composerAuth'])->name('composer-auth');
    Route::post('php', [SiteBuildController::class, 'php'])->name('php');

    // Repository
    Route::post('push', [SiteSourceController::class, 'push'])->name('push');
    Route::post('root', [SiteSourceController::class, 'root'])->name('root');
    Route::post('branches', [SiteSourceController::class, 'branches'])->name('branches');
    Route::post('branch', [SiteSourceController::class, 'branch'])->name('branch');
    Route::post('github-app', [SiteSourceController::class, 'useGitHubApp'])->name('github-app');

    // Previews
    Route::post('previews', [SitePreviewsController::class, 'create'])->name('previews.create');
    Route::post('previews/settings', [SitePreviewsController::class, 'settings'])->name('previews.settings');
    Route::post('previews/{preview}/delete', [SitePreviewsController::class, 'delete'])->whereNumber('preview')->name('previews.delete');

    // Deploy, project, delete
    Route::post('deploy', [SiteLifecycleController::class, 'deploy'])->name('deploy');
    Route::post('project', [SiteLifecycleController::class, 'moveProject'])->name('project');
    Route::post('delete', [SiteLifecycleController::class, 'delete'])->name('delete');
});

<?php

declare(strict_types=1);

use App\Http\Controllers\AgentDownloadController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\GatewayEventController;
use App\Http\Controllers\GitHubController;
use Illuminate\Support\Facades\Route;

/*
 * Enrollment is deliberately the only unauthenticated route in the system.
 *
 * Everything else an agent does happens over a mutually authenticated session
 * where identity comes from a client certificate. This endpoint exists
 * because a node that has just booted does not have one yet.
 *
 * The rate limit bounds the cost of someone pointing a load generator at the
 * one route that does public key cryptography. It is not there to stop token
 * guessing; 256 bits of entropy already does that.
 */
Route::post('/enroll', [EnrollmentController::class, 'store'])
    ->middleware('throttle:enrollment')
    ->name('enroll');

/*
 * The gateway posts here when a node connects, disconnects, or produces
 * output. It is the only way the control plane learns which of the nodes it
 * provisioned are actually alive.
 *
 * Authenticated by the shared secret, not rate limited: a busy fleet produces
 * a lot of these legitimately, and throttling them would lose node state
 * rather than protect anything.
 */
Route::post('/gateway/events', [GatewayEventController::class, 'store'])
    ->name('gateway.events');

/*
 * The agent binary, fetched by cloud-init before a node has any credential.
 *
 * Unauthenticated by necessity: a booting Droplet has nothing to authenticate
 * with, which is the whole reason enrollment exists. What makes it safe is the
 * checksum embedded in the cloud-init the control plane generated, which the
 * node verifies before making the file executable.
 *
 * The binary is not a secret either. It is the same build for every customer,
 * and holding it grants nothing without a token and an organisation CA.
 */
Route::post('/git/hook/{site}/{secret}', [\App\Http\Controllers\GitWebhookController::class, 'push'])
    ->whereNumber('site')->where('secret', '[0-9a-f]{40}')
    ->middleware('throttle:60,1')
    ->name('git.hook');

Route::get('/agent/{platform}', [AgentDownloadController::class, 'show'])
    ->name('agent.download');

Route::get('/agent/{platform}/sha256', [AgentDownloadController::class, 'checksum'])
    ->name('agent.checksum');

/*
 * GitHub webhook deliveries for one organisation's app. Authenticated by the
 * HMAC signature over the body, with the secret GitHub issued at setup.
 */
Route::post('/github/webhook/{organization}', [GitHubController::class, 'webhook'])
    ->where('organization', '[A-Za-z0-9_-]+')
    ->middleware('throttle:120,1')
    ->name('github.webhook');

// The public API: a bearer token from the console's API page, scoped read,
// deploy or manage. See docs/api.md.
Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    $c = \App\Http\Controllers\Api\V1Controller::class;
    Route::middleware(\App\Http\Middleware\ApiToken::class.':read')->group(function () use ($c) {
        Route::get('/whoami', [$c, 'whoami']);
        Route::get('/projects', [$c, 'projects']);
        Route::get('/services', [$c, 'services']);
        Route::get('/services/{service}', [$c, 'service']);
        Route::get('/services/{service}/backups', [$c, 'serviceBackups']);
        Route::get('/sites', [$c, 'sites']);
        Route::get('/sites/{site}', [$c, 'site']);
        Route::get('/sites/{site}/logs', [$c, 'logs']);
        Route::get('/sites/{site}/env', [$c, 'env']);
        Route::get('/sites/{site}/backups', [$c, 'backups']);
        Route::get('/sites/{site}/previews', [$c, 'previews']);
        Route::get('/operations/{id}', [$c, 'operation'])->whereNumber('id');
        Route::get('/servers', [$c, 'servers']);
        Route::get('/providers', [$c, 'providers']);
    });
    Route::middleware(\App\Http\Middleware\ApiToken::class.':deploy')->group(function () use ($c) {
        Route::post('/services/{service}/{action}', [$c, 'serviceAction'])->whereIn('action', \App\Lib\Services\Services::ACTIONS);
        Route::post('/sites/{site}/deploy', [$c, 'deploy']);
        Route::post('/sites/{site}/rollback', [$c, 'rollback']);
        Route::post('/sites/{site}/backups', [$c, 'backUp']);
        Route::post('/sites/{site}/previews', [$c, 'createPreview']);
        Route::delete('/sites/{site}', [$c, 'deleteSite']);
    });
    Route::middleware(\App\Http\Middleware\ApiToken::class.':manage')->group(function () use ($c) {
        Route::patch('/sites/{site}/env', [$c, 'setEnv']);
        Route::post('/sites', [$c, 'createSite']);
        Route::delete('/servers/{node}', [$c, 'deleteServer']);
        Route::post('/sites/{site}/artisan', [$c, 'artisan']);
        Route::post('/sites/{site}/restore', [$c, 'restore']);
        Route::post('/services/{service}/backup', [$c, 'serviceBackup']);
        Route::post('/services/{service}/update', [$c, 'serviceUpdate']);
    });
});

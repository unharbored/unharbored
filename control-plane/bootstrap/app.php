<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['member' => \App\Http\Middleware\EnsureMember::class, 'site-active' => \App\Http\Middleware\EnsureSiteActive::class]);
        $middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A failed validation flashes the input back; these are secrets (keys, tokens, passphrases, the .env text) or too large to keep in the session.
        $exceptions->dontFlash(['certKey', 'certChain', 'registryToken', 'private_key', 'passphrase', 'envText', 'token', 'key', 'secret', 'access_key_id', 'secret_access_key', 'authkey']);
        // A page checks permissions while it renders, and Laravel wraps what a view throws, so a refused role would otherwise see a 500 with a stack trace.
        $exceptions->render(function (\Illuminate\View\ViewException $e, Request $request) {
            if ($e->getPrevious() instanceof \Illuminate\Auth\Access\AuthorizationException && ! $request->expectsJson()) {
                return \Inertia\Inertia::render('Errors/Forbidden', ['host' => $request->getHost()])->toResponse($request)->setStatusCode(403);
            }
        });
        // An archived application refuses every change: a message on the page, or a 409 for JSON and the API.
        $exceptions->render(function (\App\Lib\Sites\SiteArchived $e, Request $request) {
            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['error' => $e->getMessage()], 409)
                : back()->withErrors(['archived' => $e->getMessage()]);
        });
        // A refused page is a page, not Laravel's bare error screen (which Inertia would show in a modal).
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, Request $request) {
            if ($response->getStatusCode() === 403 && ! $request->expectsJson() && ! $request->is('api/*')) {
                // A message someone chose on purpose (not Laravel's stock one) is shown as it is.
                $message = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getMessage() !== '' && ! str_starts_with($e->getMessage(), 'This action is unauthorized') ? $e->getMessage() : null;

                return \Inertia\Inertia::render('Errors/Forbidden', ['host' => $request->getHost(), 'message' => $message])->toResponse($request)->setStatusCode(403);
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

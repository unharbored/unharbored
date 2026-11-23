<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Operations\OperationRunner;
use App\Lib\Servers\PhpSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** What runs on a server: PHP versions and their settings, and the database services. */
final class ServerStackController extends Controller
{
    /** A branch's settings in force on a server, to fill the form. */
    public function phpSettings(string $node, string $version): JsonResponse
    {
        Gate::authorize('view');
        $this->mine($node);

        return response()->json(PhpSettings::for($node, $version));
    }

    /** Saves a branch's settings and queues the operation that writes them, checked by PHP-FPM. */
    public function applyPhpSettings(Request $request, string $node, string $version): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        try {
            PhpSettings::save($node, $version, [
                'upload_max_filesize' => (string) $request->input('upload'), 'post_max_size' => (string) $request->input('post'), 'memory_limit' => (string) $request->input('memory'),
                'max_execution_time' => (int) $request->input('time'), 'pm_max_children' => (int) $request->input('children'), 'extra' => (string) $request->input('extra', ''),
            ]);
            $id = app(OperationRunner::class)->queuePhpSettings($node, $version, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phpSettings' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    public function installPhp(Request $request, string $node, string $version): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        try {
            $id = app(OperationRunner::class)->queueInstallPhp($node, $version, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['php' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    public function updatePhp(Request $request, string $node, string $version): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        try {
            $id = app(OperationRunner::class)->queueUpdatePhp($node, $version, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['php' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    public function installService(Request $request, string $node, string $service): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        try {
            $id = app(OperationRunner::class)->queueServiceInstall($node, $service, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['service' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    private function mine(string $node): void
    {
        abort_unless(DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $node)->exists(), 404);
    }

    private function toOperation(int $id): SymfonyResponse
    {
        return Inertia::location("/operation?operation={$id}");
    }
}

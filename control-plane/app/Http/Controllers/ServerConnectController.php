<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Providers\Custom\SshAdoption;
use App\Lib\Auth\Org;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Connect a server you provide, over SSH, once. The key or password goes in one
 * POST, is never flashed back, and is held only until the agent is installed.
 */
final class ServerConnectController
{

    public function __construct(private readonly SshAdoption $adoptions) {}

    public function show(Request $request): Response
    {
        \Illuminate\Support\Facades\Gate::authorize('infrastructure');

        return Inertia::render('Servers/Connect', [
            'old' => $request->old(),
            'outboundIp' => (string) (config('control_plane.outbound_ip') ?: gethostbyname((string) config('control_plane.server_name'))),
            'serverName' => (string) config('control_plane.server_name'),
        ]);
    }

    public function store(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        \Illuminate\Support\Facades\Gate::authorize('infrastructure');
        $data = $request->validate([
            'name' => 'required|string|max:31', 'host' => 'required|string|max:253', 'port' => 'nullable|integer|min:1|max:65535',
            'user' => 'nullable|string|max:32', 'private_key' => 'nullable|string|max:20000', 'passphrase' => 'nullable|string|max:500',
            'password' => 'nullable|string|max:500', 'fingerprint' => 'nullable|string|max:80',
            'role' => 'nullable|in:'.\App\Lib\Providers\Incus\IncusHost::ROLE,
        ]);

        try {
            $operation = $this->adoptions->start(Org::id(), strtolower(trim($data['name'])), trim($data['host']), (int) ($data['port'] ?? 22),
                trim($data['user'] ?? '') ?: 'root', $data['private_key'] ?? null, $data['passphrase'] ?? null, $data['password'] ?? null,
                $data['fingerprint'] ?? null, (string) $request->user()->email, $data['role'] ?? null);
        } catch (InvalidArgumentException $e) {
            [$field, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, $e->getMessage());
            $field = ['key' => 'private_key', 'fingerprint' => 'fingerprint'][$field] ?? $field;

            // The key and password are never flashed back.
            return back()->withErrors([$field => $message])->onlyInput('name', 'host', 'port', 'user', 'fingerprint', 'role');
        }

        return Inertia::location("/operation?operation={$operation}");
    }
}

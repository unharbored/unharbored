<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Operations\OperationRunner;
use App\Lib\Servers\AdminAccess;
use App\Lib\Servers\Firewall;
use App\Lib\Servers\SshKeys;
use App\Lib\Servers\Tailscale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** How people reach a server: the sudo password, SSH lock-down, firewall, tailnet and keys. */
final class ServerAccessController extends Controller
{
    /** The server's sudo password. Reading it is audited like revealing an application's variables. */
    public function reveal(Request $request, string $node): JsonResponse
    {
        Gate::authorize('console');
        $row = $this->node($node);

        return response()->json(['password' => app(AdminAccess::class)->reveal($row->organization_id, $node, Actor::user((string) $request->user()->id, $request->user()->email))]);
    }

    /** Makes a new sudo password and queues the operation that sets it on the server. */
    public function rotate(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $row = $this->node($node);
        app(AdminAccess::class)->rotate($row->organization_id, $node, Actor::user((string) $request->user()->id, $request->user()->email));

        return $this->toOperation(app(OperationRunner::class)->queueAdminAccess($node, 'console', (string) $request->user()->email));
    }

    /** Locks SSH down or puts it back. Locking down is refused unless a key is installed, or nobody could get in. */
    public function harden(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->node($node);
        $on = $request->boolean('on');
        if ($on && ($why = AdminAccess::hardenBlock($node)) !== null) {
            throw ValidationException::withMessages(['harden' => $why]);
        }

        return $this->toOperation(app(OperationRunner::class)->queueSshHardening($node, $on, 'console', (string) $request->user()->email));
    }

    /** Saves a server's firewall and its allow-lists, and applies them. */
    public function firewall(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->node($node);
        $enabled = $request->boolean('enabled');
        $sshPrivate = $request->boolean('sshPrivate');
        $webPrivate = $request->boolean('webPrivate');
        try {
            $sshList = Firewall::parse((string) $request->input('ssh', ''), 'SSH');
            $webList = Firewall::parse((string) $request->input('web', ''), 'Web');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['firewall' => $e->getMessage()]);
        }
        if ($sshPrivate || $webPrivate) {
            $problem = match (true) {
                ! $enabled => 'Tailnet only needs the firewall turned on.',
                Tailscale::info($node) === null => 'Join this server to the tailnet first.',
                $sshPrivate && ! $request->boolean('reach') => 'Confirm that you can already reach this server over the tailnet. Closing SSH to everyone else cannot be checked from here.',
                $webPrivate && $this->hasCertificate($node) => 'An application here has a certificate, and renewing it needs the web ports open to the internet. Tailnet only is not offered for the web ports on this server.',
                default => null,
            };
            if ($problem !== null) {
                throw ValidationException::withMessages(['firewall' => $problem]);
            }
        }
        if (\App\Lib\Providers\Incus\IncusHost::reserved(DB::table('nodes')->where('node_id', $node)->value('role'))) {
            throw ValidationException::withMessages(['firewall' => 'An Incus host and its ingress keep the firewall Unharbored set up; it is not managed here.']);
        }
        Firewall::save($node, $enabled, $sshList, $webList, $sshPrivate, $webPrivate);

        return $this->toOperation(app(OperationRunner::class)->queueFirewall($node, 'console', (string) $request->user()->email));
    }

    /** Installs Tailscale on the server and joins the organisation's tailnet. */
    public function tailscaleJoin(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->node($node);
        if (! DB::table('cloud_credentials')->where('organization_id', Org::id())->where('provider', Tailscale::PROVIDER)->whereNull('revoked_at')->exists()) {
            throw ValidationException::withMessages(['tailscale' => 'Save a Tailscale auth key under Account, Providers first.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueTailscaleJoin($node, 'console', (string) $request->user()->email));
    }

    public function tailscaleLeave(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->node($node);

        return $this->toOperation(app(OperationRunner::class)->queueTailscaleLeave($node, 'console', (string) $request->user()->email));
    }

    /** Asks for an SSH key on this server, or for it to go, and queues the operation that writes it. */
    public function key(Request $request, string $node, int $key): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $row = $this->node($node);
        try {
            app(SshKeys::class)->setOnServer($row->organization_id, $node, $key, $request->boolean('installed'), (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['access' => $e->getMessage()]);
        }

        return $this->toOperation(app(OperationRunner::class)->queueAdminAccess($node, 'console', (string) $request->user()->email));
    }

    private function node(string $node): object
    {
        return DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $node)->first() ?? abort(404);
    }

    private function hasCertificate(string $node): bool
    {
        $ids = DB::table('site_nodes')->where('node_id', $node)->pluck('site_id');

        return DB::table('sites')->where('tls', true)->where(fn ($q) => $q->where('node_id', $node)->orWhereIn('id', $ids))->exists();
    }

    private function toOperation(int $id): SymfonyResponse
    {
        return Inertia::location("/operation?operation={$id}");
    }
}

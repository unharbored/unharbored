<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Dns\Lookup;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Sites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The web server: the domain, HTTPS, nginx limits and snippet, and password protection. */
final class SiteWebController extends SiteSettingsBase
{
    /** Checks the new name points at this site's server, then moves it as an operation. */
    public function moveDomain(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $request->merge(['newDomain' => (string) $request->input('newDomain', '')]);
        $domain = $request->validate(['newDomain' => 'required|string|max:253'], [], ['newDomain' => 'newDomain'])['newDomain'];
        $row = $this->row($site);
        $domain = strtolower(trim($domain));
        try {
            Sites::validate($row->name, $domain, $row->repository, $row->branch);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['newDomain' => $e->getMessage()]);
        }
        $current = Lookup::addresses($row->domain);
        $next = Lookup::addresses($domain);
        if ($next === [] || array_intersect($current, $next) === []) {
            throw ValidationException::withMessages(['newDomain' => "{$domain} does not resolve to this site's server yet. Point an A or AAAA record at it first."]);
        }

        return $this->toOperation(app(OperationRunner::class)->queueDomain($row->id, $domain, 'console', $this->email()));
    }

    /** Gives the site a free <name>.<server address>.sslip.io name, which points at its server without any DNS. */
    public function freeAddress(int $site): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $domain = NewApplications::freeAddress($row);
        if ($domain === null) {
            throw ValidationException::withMessages(['newDomain' => 'No free address is available: the site already has one, sits behind a load balancer, or its server has no public address recorded.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueDomain($row->id, $domain, 'console', $this->email()));
    }

    public function https(int $site): Response
    {
        Gate::authorize('edit');

        return $this->toOperation(app(OperationRunner::class)->queueHttps($this->row($site)->id, 'console', $this->email()));
    }

    /** Saves the settings and queues the operation that writes them to the servers, checked by nginx. */
    public function apply(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $upload = $request->integer('upload');
        $timeout = $request->integer('timeout');
        $row = $this->row($site);
        try {
            // A value equal to the default is stored as "default", so a later change of it reaches this site.
            $row = app(Sites::class)->setNginx($row, $upload === Sites::DEFAULT_UPLOAD_MB ? null : $upload,
                $timeout === Sites::DEFAULT_TIMEOUT_S ? null : $timeout, (string) $request->input('snippet', ''));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['web' => $e->getMessage()]);
        }

        return $this->toOperation(app(OperationRunner::class)->queueWebServer((int) $row->id, 'console', $this->email()));
    }

    /** Puts a username and password in front of the application, and applies it to its servers. */
    public function saveAuth(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        try {
            $row = app(Sites::class)->setBasicAuth($row, trim((string) $request->input('user', '')), (string) $request->input('password', ''));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['auth' => $e->getMessage()]);
        }

        return $this->toOperation(app(OperationRunner::class)->queueWebServer((int) $row->id, 'console', $this->email()));
    }

    public function removeAuth(int $site): Response
    {
        Gate::authorize('edit');
        $row = app(Sites::class)->clearBasicAuth($this->row($site));

        return $this->toOperation(app(OperationRunner::class)->queueWebServer((int) $row->id, 'console', $this->email()));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Org;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The audit log, read-only: nothing here changes it. */
final class AuditController extends Controller
{
    private const RESOURCES = ['site', 'node', 'credential', 'github_app', 'repository', 'resource'];

    public function index(Request $request): Response
    {
        Gate::authorize('audit.read');
        $filters = [
            'q' => mb_substr((string) $request->query('q', ''), 0, 100),
            'resource' => (string) $request->query('resource', ''),
            'failed' => $request->boolean('failed'),
            'limit' => min(max((int) $request->query('limit', 50), 10), 500),
        ];

        return Inertia::render('Audit', [
            'filters' => $filters,
            'records' => fn () => $this->records($filters),
            // Walks the whole chain: asked for when the page opens, not again when only a filter changes.
            'chain' => fn () => $this->chain(),
        ]);
    }

    /** The newest matching records. */
    private function records(array $f): array
    {
        $query = DB::table('audit_records')->where('organization_id', Org::id());

        if ($f['q'] !== '') {
            // Unescaped: % and _ in a search only widen it, and escaping
            // them needs an ESCAPE clause SQLite and MySQL spell alike.
            $like = '%'.$f['q'].'%';
            $query->where(fn ($q) => $q->where('action', 'like', $like)->orWhere('resource_id', 'like', $like)
                ->orWhere('actor_label', 'like', $like)->orWhere('metadata', 'like', $like));
        }
        if (in_array($f['resource'], self::RESOURCES, true)) {
            $query->where('resource_type', $f['resource'] === 'credential' ? 'cloud_credential' : $f['resource']);
        }
        if ($f['failed']) {
            $query->where('outcome', '!=', 'succeeded');
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('sequence')->limit($f['limit'])->get();

        return [
            'total' => $total,
            'rows' => $rows->map(fn ($r) => [
                'seq' => (int) $r->sequence,
                'when' => Carbon::parse($r->recorded_at)->format('j M H:i:s'),
                'actor' => $r->actor_label ?: $r->actor_type,
                'action' => $r->action,
                'resource' => $r->resource_id ?? '',
                'detail' => $this->detail($r),
                'failed' => $r->outcome !== 'succeeded',
                'hash' => substr((string) $r->hash, 0, 4).'…'.substr((string) $r->hash, -4),
            ])->all(),
        ];
    }

    /** @return array{intact: bool, checked: int, describe: string} */
    private function chain(): array
    {
        $result = app(AuditLog::class)->verify(Org::id());

        return ['intact' => $result->intact, 'checked' => $result->recordsChecked, 'describe' => $result->describe()];
    }

    private function detail(object $r): string
    {
        $meta = json_decode((string) $r->metadata, true) ?: [];
        $parts = [];
        foreach ($meta as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $parts[] = "{$key} ".mb_strimwidth((string) $value, 0, 80, '…');
            }
        }
        if ($r->reason) {
            array_unshift($parts, (string) $r->reason);
        }

        return implode(' · ', array_slice($parts, 0, 4));
    }
}

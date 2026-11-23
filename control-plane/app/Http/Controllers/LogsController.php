<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Sites\Sites;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Log lines from every server (Logs), or one application's (its Logs tab, `?site=`). */
final class LogsController extends Controller
{
    private const RANGES = ['15m' => 15, '1h' => 60, '24h' => 1440, '7d' => 10080];

    public function index(Request $request): Response
    {
        Gate::authorize('view');
        $filters = [
            'q' => (string) $request->query('q', ''),
            'server' => (string) $request->query('server', ''),
            'source' => (string) $request->query('source', ''),
            'range' => (string) $request->query('range', '1h'),
            // '' = every line, 'warn' = warnings and worse, 'bad' = errors only.
            'level' => (string) $request->query('level', ''),
            'site' => (int) $request->query('site', 0),
            'limit' => min(max((int) $request->query('limit', 200), 50), 1000),
        ];

        return Inertia::render('Logs', [
            'filters' => $filters,
            'choices' => fn () => $this->choices($filters['site']),
            'lines' => fn () => $this->lines($filters),
        ]);
    }

    /**
     * The lines one application owns: its Laravel, worker and process logs, and nginx's
     * errors on the servers it runs on. All lines when no application is named.
     */
    private function scoped(int $site): Builder
    {
        $query = DB::table('log_lines')->where('organization_id', Org::id());
        $row = $site > 0 ? DB::table('sites')->where('organization_id', Org::id())->where('id', $site)->first() : null;
        if ($row !== null) {
            $query->whereIn('node_id', Sites::nodes($row))->whereIn('source', ["laravel:{$row->name}", "worker:{$row->name}", "app:{$row->name}", "nginx.error:{$row->name}", 'nginx.error']);
        } elseif ($site > 0) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /** Servers and sources that have logged this week, for the filters. */
    private function choices(int $site): array
    {
        $since = now()->subDays(7);

        return [
            'servers' => $this->scoped($site)->where('logged_at', '>=', $since)->distinct()->orderBy('node_id')->pluck('node_id')
                ->map(fn ($n) => ['id' => $n, 'name' => strstr($n, '.', true) ?: $n])->all(),
            'sources' => $this->scoped($site)->where('logged_at', '>=', $since)->distinct()->orderBy('source')->pluck('source')->all(),
        ];
    }

    /**
     * The newest matching lines. Searched with LIKE, which is plenty at a
     * week of a few servers; the text was redacted before it was stored.
     */
    private function lines(array $f): array
    {
        $query = $this->scoped($f['site'])->where('logged_at', '>=', now()->subMinutes(self::RANGES[$f['range']] ?? 60));
        if ($f['server'] !== '') {
            $query->where('node_id', $f['server']);
        }
        if ($f['source'] !== '') {
            $query->where('source', $f['source']);
        }
        if ($f['level'] !== '') {
            $query->where(function ($q) use ($f) {
                foreach (array_merge(['.ERROR', '.CRITICAL', '.ALERT', '.EMERGENCY', '[error]', '[crit]', '[alert]', '[emerg]'], $f['level'] === 'warn' ? ['.WARNING', '[warn]'] : []) as $marker) {
                    $q->orWhere('text', 'like', '%'.$marker.'%');
                }
            });
        }
        if ($f['q'] !== '') {
            $query->where('text', 'like', '%'.mb_substr($f['q'], 0, 200).'%');
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('logged_at')->orderByDesc('id')->limit($f['limit'])->get();

        return [
            'total' => $total,
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'at' => Carbon::parse($r->logged_at)->utc()->format('m-d H:i:s'),
                'server' => strstr($r->node_id, '.', true) ?: $r->node_id,
                'source' => $r->source,
                'text' => $r->text,
                // A record that spans lines (an exception and its stack trace) shows its first line and folds the rest.
                'head' => strtok($r->text, "\n"),
                'rest' => str_contains($r->text, "\n") ? substr($r->text, strpos($r->text, "\n") + 1) : '',
                'level' => preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY)\b|\[(error|crit|alert|emerg)\]/', $r->text) ? 'bad'
                    : (preg_match('/\.WARNING\b|\[warn\]/', $r->text) ? 'warn' : ''),
            ])->all(),
        ];
    }
}

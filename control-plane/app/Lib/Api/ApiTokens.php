<?php

declare(strict_types=1);

namespace App\Lib\Api;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SensitiveParameter;
use stdClass;

/**
 * Tokens for the API, the cm CLI and its MCP server.
 *
 * A token is `cm_` and 40 random characters, shown once when made; only its
 * SHA-256 is kept, so a copy of the database grants nothing. Each carries
 * scopes, each wider than the last:
 *
 *   read    sites, servers, operations, logs, backups, .env key names
 *   deploy  read, plus deploy, roll back and back up
 *   manage  deploy, plus .env contents, artisan and restores
 */
final class ApiTokens
{
    public const SCOPES = ['read', 'deploy', 'manage'];

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @return array{token: string, id: int} the token, which is never shown again
     */
    public function issue(string $organizationId, string $name, string $scope, string $createdBy): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Name the token, up to 80 characters: where it is used, say.');
        }
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Scope is read, deploy or manage.');
        }
        $token = 'cm_'.bin2hex(random_bytes(20));
        $id = DB::table('api_tokens')->insertGetId([
            'organization_id' => $organizationId, 'name' => $name, 'hash' => hash('sha256', $token), 'prefix' => substr($token, 0, 10),
            'scopes' => json_encode(array_slice(self::SCOPES, 0, array_search($scope, self::SCOPES, true) + 1)),
            'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record($organizationId, Actor::system('control-plane'), 'api_token.created', resourceType: 'api_token',
            resourceId: (string) $id, metadata: ['name' => $name, 'scope' => $scope, 'by' => $createdBy]);

        return ['token' => $token, 'id' => $id];
    }

    /** The live token this is, or null. Marks it used. */
    public function find(#[SensitiveParameter] string $token): ?stdClass
    {
        if (! preg_match('/^cm_[0-9a-f]{40}$/', $token)) {
            return null;
        }
        $row = DB::table('api_tokens')->where('hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        if ($row !== null && ($row->last_used_at === null || now()->diffInMinutes($row->last_used_at, true) >= 1)) {
            DB::table('api_tokens')->where('id', $row->id)->update(['last_used_at' => now()]);
        }

        return $row;
    }

    public static function allows(stdClass $token, string $scope): bool
    {
        return in_array($scope, json_decode((string) $token->scopes, true) ?: [], true);
    }

    public function revoke(string $organizationId, int $id, string $by): void
    {
        $updated = DB::table('api_tokens')->where('organization_id', $organizationId)->where('id', $id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw new InvalidArgumentException('No such live token.');
        }
        $this->audit->record($organizationId, Actor::system('control-plane'), 'api_token.revoked', resourceType: 'api_token',
            resourceId: (string) $id, metadata: ['by' => $by]);
    }

    public static function actor(stdClass $token): Actor
    {
        return Actor::apiToken((string) $token->id, "token {$token->name}");
    }
}

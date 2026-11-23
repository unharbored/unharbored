<?php

declare(strict_types=1);

namespace App\Lib\Providers\Linode;

use App\Lib\Providers\TokenApiClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/** Linode's v4 API (Akamai Connected Cloud). Its errors are {"errors": [{"reason": "..."}]}. */
final class LinodeClient extends TokenApiClient
{
    public const BASE = 'https://api.linode.com/v4';

    public function __construct(HttpFactory $http, #[SensitiveParameter] string $token, string $base = self::BASE)
    {
        parent::__construct($http, $token, $base);
    }

    protected function name(): string
    {
        return 'Linode';
    }

    protected function message(Response $response): string
    {
        $reasons = array_filter(array_map(fn ($e) => $e['reason'] ?? null, (array) $response->json('errors')));

        return $reasons === [] ? 'HTTP '.$response->status() : implode('; ', $reasons);
    }
}

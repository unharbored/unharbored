<?php

declare(strict_types=1);

namespace App\Lib\Providers\Vultr;

use App\Lib\Providers\TokenApiClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/** Vultr's v2 API. Its errors are {"error": "...", "status": 401}. */
final class VultrClient extends TokenApiClient
{
    public const BASE = 'https://api.vultr.com/v2';

    public function __construct(HttpFactory $http, #[SensitiveParameter] string $token, string $base = self::BASE)
    {
        parent::__construct($http, $token, $base);
    }

    protected function name(): string
    {
        return 'Vultr';
    }

    protected function message(Response $response): string
    {
        return (string) ($response->json('error') ?? 'HTTP '.$response->status());
    }
}

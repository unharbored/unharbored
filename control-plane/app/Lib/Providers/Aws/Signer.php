<?php

declare(strict_types=1);

namespace App\Lib\Providers\Aws;

use SensitiveParameter;

/**
 * AWS Signature Version 4, written out rather than pulled in as the SDK. EC2,
 * STS and SSM calls are a few form-encoded or JSON posts; the SDK is large and
 * would cost more than it saves until managed services (ALB, RDS) arrive.
 * Tested against the vector in AWS's published signing suite.
 */
final class Signer
{
    /**
     * The Authorization header value, and the signed date it used.
     *
     * @param  array<string, string>  $headers  every header to sign, including host and x-amz-date
     */
    public static function authorization(
        string $method,
        string $path,
        string $query,
        array $headers,
        string $body,
        string $keyId,
        #[SensitiveParameter] string $secret,
        string $region,
        string $service,
        string $amzDate,
    ): string {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        }
        ksort($lower);

        $canonicalHeaders = '';
        foreach ($lower as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }
        $signedHeaders = implode(';', array_keys($lower));
        $date = substr($amzDate, 0, 8);
        $scope = "{$date}/{$region}/{$service}/aws4_request";

        $canonical = implode("\n", [$method, $path, $query, $canonicalHeaders, $signedHeaders, hash('sha256', $body)]);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$secret, true), true), true), true);
        $signature = hash_hmac('sha256', $toSign, $key);

        return "AWS4-HMAC-SHA256 Credential={$keyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    }
}

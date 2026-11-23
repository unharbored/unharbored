<?php

declare(strict_types=1);

namespace App\Lib\Providers\Aws;

use App\Lib\Providers\Rejected;
use App\Lib\Providers\Uncertain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use SensitiveParameter;
use SimpleXMLElement;

/**
 * Signed calls to EC2, STS and SSM. An endpoint URL, when set, replaces every
 * service's address: that is how LocalStack and other AWS-compatible services
 * are reached. Region still shapes the signature.
 *
 * Errors follow the provider-neutral rule: a 4xx is Rejected (nothing
 * happened); a connection failure or 5xx is Uncertain (it may have).
 */
final class AwsClient
{
    public const EC2_VERSION = '2016-11-15';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $keyId,
        #[SensitiveParameter] private readonly string $secret,
        private readonly string $region,
        private readonly ?string $endpoint = null,
        private readonly ?\Closure $clock = null,
    ) {}

    public function region(): string
    {
        return $this->region;
    }

    /** The same credentials against another region. */
    public function inRegion(string $region): self
    {
        return new self($this->http, $this->keyId, $this->secret, $region, $this->endpoint, $this->clock);
    }

    /**
     * An EC2 Query API call: Action plus parameters, XML back.
     *
     * @param  array<string, scalar>  $params
     */
    public function ec2(string $action, array $params = []): SimpleXMLElement
    {
        return $this->xml('ec2', ['Action' => $action, 'Version' => self::EC2_VERSION] + $params);
    }

    /** @return array{Account: string, Arn: string, UserId: string} */
    public function whoAmI(): array
    {
        $x = $this->xml('sts', ['Action' => 'GetCallerIdentity', 'Version' => '2011-06-15']);
        $r = $x->GetCallerIdentityResult;

        return ['Account' => (string) $r->Account, 'Arn' => (string) $r->Arn, 'UserId' => (string) $r->UserId];
    }

    /** One SSM parameter's value. */
    public function ssmParameter(string $name): string
    {
        $body = json_encode(['Name' => $name], JSON_THROW_ON_ERROR);
        $response = $this->send('ssm', $body, ['Content-Type' => 'application/x-amz-json-1.1', 'X-Amz-Target' => 'AmazonSSM.GetParameter']);
        $value = json_decode($response, true)['Parameter']['Value'] ?? null;

        return is_string($value) ? $value : throw new Rejected("SSM returned no value for {$name}.");
    }

    /** @param array<string, scalar> $params */
    private function xml(string $service, array $params): SimpleXMLElement
    {
        $raw = $this->send($service, http_build_query($params, '', '&', PHP_QUERY_RFC3986), ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8']);
        $xml = @simplexml_load_string(preg_replace('/ xmlns="[^"]*"/', '', $raw) ?? $raw);

        return $xml ?: throw new Uncertain('AWS sent an answer that is not XML.');
    }

    /** @param array<string, string> $extra */
    private function send(string $service, string $body, array $extra): string
    {
        $url = $this->endpoint !== null && $this->endpoint !== '' ? rtrim($this->endpoint, '/') : "https://{$service}.{$this->region}.amazonaws.com";
        $host = parse_url($url, PHP_URL_HOST).(($port = parse_url($url, PHP_URL_PORT)) ? ":{$port}" : '');
        $amzDate = $this->clock !== null ? ($this->clock)() : gmdate('Ymd\THis\Z');

        $headers = ['Host' => $host, 'X-Amz-Date' => $amzDate] + $extra;
        $headers['Authorization'] = Signer::authorization('POST', '/', '', $headers, $body, $this->keyId, $this->secret, $this->region, $service, $amzDate);
        unset($headers['Host']);

        try {
            $response = $this->http->withHeaders($headers)->withBody($body, $extra['Content-Type'])->timeout(30)->post($url.'/');
        } catch (ConnectionException $e) {
            throw new Uncertain('Could not reach AWS: '.$e->getMessage(), 0, $e);
        }

        if ($response->successful()) {
            return $response->body();
        }

        $message = $this->errorMessage($response->body()) ?: "HTTP {$response->status()}";
        throw $response->status() >= 500
            ? new Uncertain("AWS error: {$message}")
            : new Rejected($message);
    }

    /** "Code: message" out of an EC2/STS XML error or an SSM JSON error. */
    private function errorMessage(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return trim(($json['__type'] ?? '').': '.($json['message'] ?? $json['Message'] ?? ''), ': ');
        }
        $xml = @simplexml_load_string(preg_replace('/ xmlns="[^"]*"/', '', $body) ?? $body);
        if ($xml === false) {
            return '';
        }
        $error = $xml->Errors->Error ?? $xml->Error ?? null;

        return $error === null ? '' : trim((string) $error->Code.': '.(string) $error->Message, ': ');
    }
}

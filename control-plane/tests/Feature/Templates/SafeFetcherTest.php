<?php

namespace Tests\Feature\Templates;

use App\Lib\Templates\FetchFailed;
use App\Lib\Templates\SafeFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SafeFetcherTest extends TestCase
{
    private function fetcher(array $ips = ['93.184.216.34']): SafeFetcher
    {
        return new SafeFetcher(fn () => $ips);
    }

    public function test_it_fetches_over_https_and_pins_the_resolved_address(): void
    {
        Http::fake(['https://t.example.com/*' => Http::response('hello')]);
        $this->assertSame('hello', $this->fetcher()->get('https://t.example.com/index.json', 1000));

        Http::assertSent(function ($request, $response = null) {
            return $request->url() === 'https://t.example.com/index.json';
        });
    }

    public function test_the_request_is_pinned_without_following_redirects(): void
    {
        $captured = null;
        Http::fake(function ($request, $options) use (&$captured) {
            $captured = $options;

            return Http::response('x');
        });
        $this->fetcher(['2606:4700::1111'])->get('https://t.example.com/a', 1000);
        $this->assertSame(['t.example.com:443:[2606:4700::1111]'], $captured['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($captured['allow_redirects']);
        $this->assertSame(10, $captured['timeout']);
    }

    #[DataProvider('notHttps')]
    public function test_only_plain_https_urls_are_fetched(string $url): void
    {
        Http::fake();
        try {
            $this->fetcher()->get($url, 1000);
            $this->fail('expected a refusal');
        } catch (FetchFailed $e) {
            $this->assertStringContainsString('https', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public static function notHttps(): array
    {
        return [['http://t.example.com/x'], ['ftp://t.example.com/x'], ['file:///etc/passwd'], ['//t.example.com/x'], ['https://user:pw@t.example.com/x'], ['https:///x']];
    }

    public function test_a_redirect_is_refused_even_to_the_same_host(): void
    {
        Http::fake([
            'https://t.example.com/a' => Http::response('', 302, ['Location' => 'https://evil.example.net/x']),
            'https://t.example.com/b' => Http::response('', 301, ['Location' => 'http://t.example.com/x']),
        ]);
        foreach (['a', 'b'] as $p) {
            try {
                $this->fetcher()->get("https://t.example.com/$p", 1000);
                $this->fail('expected a refusal');
            } catch (FetchFailed $e) {
                $this->assertStringContainsString('redirect', $e->getMessage());
            }
        }
    }

    #[DataProvider('privateAddresses')]
    public function test_names_that_resolve_to_non_public_addresses_are_refused(string $ip): void
    {
        Http::fake();
        try {
            $this->fetcher([$ip])->get('https://t.example.com/x', 1000);
            $this->fail("expected $ip to be refused");
        } catch (FetchFailed $e) {
            $this->assertStringContainsString('not a public address', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public static function privateAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], ['127.0.0.1', '127.8.8.8', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fd00::1', 'fc00::1', '::ffff:127.0.0.1']);
    }

    public function test_one_private_answer_among_public_ones_refuses_the_name(): void
    {
        Http::fake();
        $this->expectException(FetchFailed::class);
        $this->fetcher(['93.184.216.34', '10.0.0.5'])->get('https://t.example.com/x', 1000);
    }

    public function test_an_ip_literal_is_checked_too_and_a_name_that_does_not_resolve_is_refused(): void
    {
        Http::fake();
        foreach ([['https://127.0.0.1/x', ['93.184.216.34']], ['https://[::1]/x', ['93.184.216.34']], ['https://t.example.com/x', []]] as [$url, $ips]) {
            try {
                $this->fetcher($ips)->get($url, 1000);
                $this->fail("expected $url to be refused");
            } catch (FetchFailed) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    public function test_public_addresses_pass(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '172.32.0.1', '2606:4700::1111'] as $ip) {
            $this->assertTrue(SafeFetcher::isPublic($ip), $ip);
        }
    }

    public function test_an_oversize_response_is_refused(): void
    {
        Http::fake(['*' => Http::response(str_repeat('a', 2000))]);
        try {
            $this->fetcher()->get('https://t.example.com/x', 1000);
            $this->fail('expected a refusal');
        } catch (FetchFailed $e) {
            $this->assertStringContainsString('larger than the limit', $e->getMessage());
        }
        $this->assertSame(str_repeat('a', 2000), $this->fetcher()->get('https://t.example.com/x', 2000));
    }

    public function test_limits_match_the_spec(): void
    {
        $this->assertSame(1048576, SafeFetcher::MAX_INDEX);
        $this->assertSame(262144, SafeFetcher::MAX_FILE);
        $this->assertSame(10, SafeFetcher::TIMEOUT);
    }

    public function test_errors_and_failed_connections_are_fetch_failures(): void
    {
        Http::fake(['https://t.example.com/404' => Http::response('', 404), 'https://t.example.com/down' => fn () => throw new ConnectionException('nope')]);
        foreach (['404', 'down'] as $p) {
            try {
                $this->fetcher()->get("https://t.example.com/$p", 1000);
                $this->fail('expected a refusal');
            } catch (FetchFailed) {
                $this->assertTrue(true);
            }
        }
    }
}

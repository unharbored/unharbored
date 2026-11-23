<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Lib\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** RFC 6238 appendix B, SHA-1, reduced from eight digits to six. */
    public static function rfcVectors(): array
    {
        return [[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037'], [20000000000, '353130']];
    }

    #[DataProvider('rfcVectors')]
    public function test_codes_match_the_rfc(int $time, string $code): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $this->assertSame($code, Totp::code($secret, intdiv($time, 30)));
    }

    public function test_a_code_is_accepted_for_one_step_either_side(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;

        foreach ([-1, 0, 1] as $offset) {
            $this->assertNotNull(Totp::verify($secret, Totp::code($secret, intdiv($now, 30) + $offset), null, $now));
        }
        $this->assertNull(Totp::verify($secret, Totp::code($secret, intdiv($now, 30) + 2), null, $now));
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;
        $code = Totp::code($secret, intdiv($now, 30));

        $step = Totp::verify($secret, $code, null, $now);

        $this->assertNotNull($step);
        $this->assertNull(Totp::verify($secret, $code, $step, $now));
    }

    public function test_malformed_codes_are_refused(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12 345'] as $code) {
            $this->assertNull(Totp::verify(Totp::generateSecret(), $code));
        }
    }

    public function test_base32_round_trips(): void
    {
        $bytes = random_bytes(20);

        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        $this->assertSame(32, strlen(Totp::generateSecret()));
    }
}

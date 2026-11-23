<?php

declare(strict_types=1);

namespace App\Lib\Auth;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Time-based one-time passwords, RFC 6238: HMAC-SHA1, 30-second steps, six
 * digits, which is what every authenticator app speaks.
 *
 * Small enough to own rather than depend on, and the RFC's test vectors pin
 * it in the tests.
 */
final class Totp
{
    public const STEP_SECONDS = 30;

    private const DIGITS = 6;

    /** One step either side, for clock drift between phone and server. */
    private const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new 160-bit secret, base32 encoded as authenticator apps expect. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The step a valid code was for, or null. Codes for steps at or before
     * $lastUsedStep are refused so a code cannot be replayed.
     */
    public static function verify(#[SensitiveParameter] string $secret, string $code, ?int $lastUsedStep = null, ?int $now = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $current = intdiv($now ?? time(), self::STEP_SECONDS);

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function code(#[SensitiveParameter] string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** The otpauth:// URI an authenticator app imports, usually as a QR code. */
    public static function uri(#[SensitiveParameter] string $secret, string $account, string $issuer = 'Unharbored'): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer), self::DIGITS, self::STEP_SECONDS);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(rtrim(str_replace(' ', '', $text), '='));
        $bits = '';
        foreach (str_split($text) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                throw new InvalidArgumentException('Not a base32 secret.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}

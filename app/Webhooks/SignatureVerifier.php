<?php

namespace App\Webhooks;

/**
 * Verifies "t=<unix time>,v1=<hex hmac>" signatures (Stripe-style scheme).
 *
 * The signed string is "<timestamp>.<raw request body>". The timestamp is part
 * of the signature, so an attacker cannot replay an old request with a fresh time.
 */
final class SignatureVerifier
{
    public function __construct(private readonly int $tolerance) {}

    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * @param  list<string>  $secrets  all currently valid secrets (rotation support)
     */
    public function verify(string $payload, ?string $header, array $secrets, ?int $now = null): bool
    {
        if ($header === null || $header === '' || $secrets === []) {
            return false;
        }

        [$timestamp, $signatures] = $this->parse($header);

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > $this->tolerance) {
            return false;
        }

        foreach ($secrets as $secret) {
            $expected = self::sign($payload, $secret, $timestamp);

            foreach ($signatures as $signature) {
                // Constant-time comparison, never use === for signatures.
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}

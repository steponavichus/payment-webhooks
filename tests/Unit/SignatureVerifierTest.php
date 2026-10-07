<?php

namespace Tests\Unit;

use App\Webhooks\SignatureVerifier;
use PHPUnit\Framework\TestCase;

class SignatureVerifierTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function verifier(): SignatureVerifier
    {
        return new SignatureVerifier(tolerance: 300);
    }

    private function header(string $body, string $secret, int $timestamp = self::NOW): string
    {
        return "t={$timestamp},v1=".SignatureVerifier::sign($body, $secret, $timestamp);
    }

    public function test_accepts_a_valid_signature(): void
    {
        $body = '{"id":"evt_1"}';

        $this->assertTrue($this->verifier()->verify($body, $this->header($body, 's1'), ['s1'], self::NOW));
    }

    public function test_rejects_a_wrong_secret(): void
    {
        $body = '{"id":"evt_1"}';

        $this->assertFalse($this->verifier()->verify($body, $this->header($body, 'other'), ['s1'], self::NOW));
    }

    public function test_rejects_a_tampered_body(): void
    {
        $header = $this->header('{"amount":100}', 's1');

        $this->assertFalse($this->verifier()->verify('{"amount":999}', $header, ['s1'], self::NOW));
    }

    public function test_rejects_an_old_timestamp(): void
    {
        $body = '{}';
        $header = $this->header($body, 's1', self::NOW - 301);

        $this->assertFalse($this->verifier()->verify($body, $header, ['s1'], self::NOW));
    }

    public function test_rejects_a_timestamp_from_the_future(): void
    {
        $body = '{}';
        $header = $this->header($body, 's1', self::NOW + 301);

        $this->assertFalse($this->verifier()->verify($body, $header, ['s1'], self::NOW));
    }

    public function test_accepts_a_timestamp_inside_the_tolerance(): void
    {
        $body = '{}';
        $header = $this->header($body, 's1', self::NOW - 300);

        $this->assertTrue($this->verifier()->verify($body, $header, ['s1'], self::NOW));
    }

    public function test_the_timestamp_is_part_of_the_signature(): void
    {
        $body = '{}';
        $signature = SignatureVerifier::sign($body, 's1', self::NOW - 1000);
        $header = 't='.self::NOW.',v1='.$signature; // fresh time, old signature

        $this->assertFalse($this->verifier()->verify($body, $header, ['s1'], self::NOW));
    }

    public function test_supports_secret_rotation_on_both_sides(): void
    {
        $body = '{}';
        $newOnly = $this->header($body, 'new');
        $oldAndNew = 't='.self::NOW.',v1=bad,v1='.SignatureVerifier::sign($body, 'old', self::NOW);

        $this->assertTrue($this->verifier()->verify($body, $newOnly, ['old', 'new'], self::NOW));
        $this->assertTrue($this->verifier()->verify($body, $oldAndNew, ['old'], self::NOW));
    }

    public function test_rejects_malformed_or_missing_headers(): void
    {
        $verifier = $this->verifier();

        foreach ([null, '', 'garbage', 't=abc,v1=ff', 't='.self::NOW, 'v1=ff', 't=,v1='] as $header) {
            $this->assertFalse($verifier->verify('{}', $header, ['s1'], self::NOW), (string) $header);
        }
    }

    public function test_rejects_everything_when_no_secret_is_configured(): void
    {
        $body = '{}';

        $this->assertFalse($this->verifier()->verify($body, $this->header($body, ''), [], self::NOW));
    }
}

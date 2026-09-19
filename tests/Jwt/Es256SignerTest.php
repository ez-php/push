<?php

declare(strict_types=1);

namespace Tests\Push\Jwt;

use EzPhp\Push\Jwt\Es256Signer;
use EzPhp\Push\PushException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class Es256SignerTest
 *
 * @package Tests\Push\Jwt
 */
#[CoversClass(Es256Signer::class)]
final class Es256SignerTest extends TestCase
{
    private string $privateKeyPem = '';

    private string $publicKeyPem = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            self::markTestSkipped('EC key generation is not available in this environment.');
        }

        openssl_pkey_export($key, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;

        $details = openssl_pkey_get_details($key);
        $this->publicKeyPem = $details !== false ? $details['key'] : '';
    }

    public function testSignProducesAVerifiableThreePartToken(): void
    {
        $signer = new Es256Signer($this->privateKeyPem, 'KEY123');

        $jwt = $signer->sign(['iss' => 'TEAM123', 'iat' => 1_700_000_000]);
        $parts = explode('.', $jwt);

        $this->assertCount(3, $parts);

        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $this->assertIsArray($header);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('KEY123', $header['kid']);

        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        $this->assertIsArray($payload);
        $this->assertSame('TEAM123', $payload['iss']);

        $signature = self::base64UrlDecode($parts[2]);
        $this->assertSame(64, strlen($signature));

        $der = $this->rawToDer($signature);
        $signingInput = $parts[0] . '.' . $parts[1];

        $this->assertSame(
            1,
            openssl_verify($signingInput, $der, $this->publicKeyPem, OPENSSL_ALGO_SHA256),
        );
    }

    public function testSignOmitsTheKidHeaderWhenNoKeyIdIsGiven(): void
    {
        $signer = new Es256Signer($this->privateKeyPem);

        $header = json_decode(self::base64UrlDecode(explode('.', $signer->sign(['iss' => 'x']))[0]), true);

        $this->assertIsArray($header);
        $this->assertSame('ES256', $header['alg']);
        $this->assertArrayNotHasKey('kid', $header);
    }

    public function testSignThrowsOnInvalidPrivateKey(): void
    {
        $signer = new Es256Signer('not a valid pem key', 'KEY123');

        $this->expectException(PushException::class);

        $signer->sign(['iss' => 'TEAM123']);
    }

    /**
     * Repack a raw 64-byte R||S signature back into ASN.1 DER so
     * openssl_verify() (which expects DER) can confirm the signature is
     * mathematically valid for the known public key.
     */
    private function rawToDer(string $raw): string
    {
        $r = ltrim(substr($raw, 0, 32), "\x00");
        $s = ltrim(substr($raw, 32, 32), "\x00");

        $r = $this->derInteger($r);
        $s = $this->derInteger($s);

        $body = $r . $s;

        return "\x30" . $this->derLength(strlen($body)) . $body;
    }

    private function derInteger(string $bytes): string
    {
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derLength(int $length): string
    {
        return $length < 0x80 ? chr($length & 0xFF) : chr(0x81) . chr($length & 0xFF);
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}

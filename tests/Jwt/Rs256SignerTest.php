<?php

declare(strict_types=1);

namespace Tests\Push\Jwt;

use EzPhp\Push\Jwt\Rs256Signer;
use EzPhp\Push\PushException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class Rs256SignerTest
 *
 * @package Tests\Push\Jwt
 */
#[CoversClass(Rs256Signer::class)]
final class Rs256SignerTest extends TestCase
{
    private string $privateKeyPem = '';

    private string $publicKeyPem = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($key === false) {
            self::markTestSkipped('RSA key generation is not available in this environment.');
        }

        openssl_pkey_export($key, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;

        $details = openssl_pkey_get_details($key);
        $this->publicKeyPem = $details !== false ? $details['key'] : '';
    }

    public function testSignProducesAVerifiableThreePartToken(): void
    {
        $signer = new Rs256Signer($this->privateKeyPem);

        $jwt = $signer->sign(['iss' => 'service@example.iam.gserviceaccount.com', 'iat' => 1_700_000_000]);
        $parts = explode('.', $jwt);

        $this->assertCount(3, $parts);

        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $this->assertIsArray($header);
        $this->assertSame('RS256', $header['alg']);

        $signingInput = $parts[0] . '.' . $parts[1];
        $signature = self::base64UrlDecode($parts[2]);

        $this->assertSame(
            1,
            openssl_verify($signingInput, $signature, $this->publicKeyPem, OPENSSL_ALGO_SHA256),
        );
    }

    public function testSignThrowsOnInvalidPrivateKey(): void
    {
        $signer = new Rs256Signer('not a valid pem key');

        $this->expectException(PushException::class);

        $signer->sign(['iss' => 'service@example.iam.gserviceaccount.com']);
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}

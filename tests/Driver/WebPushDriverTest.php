<?php

declare(strict_types=1);

namespace Tests\Push\Driver;

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\Push\Driver\WebPushDriver;
use EzPhp\Push\Jwt\Es256Signer;
use EzPhp\Push\PushException;
use EzPhp\Push\PushMessage;
use EzPhp\Push\WebPush\WebPushEncryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class WebPushDriverTest
 *
 * @package Tests\Push\Driver
 */
#[CoversClass(WebPushDriver::class)]
#[UsesClass(Es256Signer::class)]
#[UsesClass(WebPushEncryptor::class)]
final class WebPushDriverTest extends TestCase
{
    private string $vapidPrivateKeyPem = '';

    private string $vapidPublicKey = '';

    private string $uaPublicKey = '';

    private string $authSecret = '';

    protected function setUp(): void
    {
        parent::setUp();

        $vapid = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ua = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($vapid === false || $ua === false) {
            self::markTestSkipped('EC key generation is not available in this environment.');
        }

        openssl_pkey_export($vapid, $pem);
        $this->vapidPrivateKeyPem = $pem;
        $this->vapidPublicKey = WebPushDriver::vapidPublicKey($pem);

        $details = openssl_pkey_get_details($ua);
        self::assertIsArray($details);
        $this->uaPublicKey = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $this->authSecret = random_bytes(16);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function subscription(string $endpoint = 'https://push.example.net:8443/send/abc123'): string
    {
        return (string) json_encode([
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => self::b64($this->uaPublicKey), 'auth' => self::b64($this->authSecret)],
        ]);
    }

    private function driver(FakeTransport $transport, int $ttl = 86400): WebPushDriver
    {
        return new WebPushDriver(
            new HttpClient($transport),
            new Es256Signer($this->vapidPrivateKeyPem),
            $this->vapidPublicKey,
            'mailto:ops@example.com',
            new WebPushEncryptor(),
            $ttl,
        );
    }

    public function testVapidPublicKeyIsAn65ByteUncompressedPointInBase64Url(): void
    {
        $raw = base64_decode(strtr($this->vapidPublicKey, '-_', '+/'), true);

        $this->assertIsString($raw);
        $this->assertSame(65, strlen($raw));
        $this->assertSame("\x04", $raw[0]);
        $this->assertStringNotContainsString('=', $this->vapidPublicKey);
    }

    public function testSendPostsAnEncryptedPayloadToTheSubscriptionEndpoint(): void
    {
        $transport = new FakeTransport(['*' => HttpResponse::fake('', 201)]);

        $this->driver($transport)->send($this->subscription(), new PushMessage('Order shipped', 'On its way', ['order_id' => '42']));

        $recorded = $transport->getRecorded();
        $this->assertCount(1, $recorded);
        $this->assertSame('POST', $recorded[0]['method']);
        $this->assertSame('https://push.example.net:8443/send/abc123', $recorded[0]['url']);
        $this->assertSame('aes128gcm', $recorded[0]['headers']['Content-Encoding']);
        $this->assertSame('application/octet-stream', $recorded[0]['headers']['Content-Type']);
        $this->assertSame('86400', $recorded[0]['headers']['TTL']);
        // salt(16) + rs(4) + idlen(1) + keyid(65) header, then the ciphertext record
        $this->assertGreaterThan(86, strlen($recorded[0]['body']));
        $this->assertStringNotContainsString('Order shipped', $recorded[0]['body']);
    }

    public function testAuthorizationCarriesAVapidJwtWithTheExpectedClaimsAndPublicKey(): void
    {
        $transport = new FakeTransport(['*' => HttpResponse::fake('', 201)]);

        $this->driver($transport)->send($this->subscription(), new PushMessage('T', 'B'));

        $authorization = $transport->getRecorded()[0]['headers']['Authorization'];
        if (preg_match('/^vapid t=([\w-]+\.[\w-]+\.[\w-]+), k=([\w-]+)$/', $authorization, $m) !== 1) {
            self::fail('Unexpected Authorization header: ' . $authorization);
        }

        $this->assertSame($this->vapidPublicKey, $m[2]);

        [$h, $c, $sig] = explode('.', $m[1]);
        $claims = json_decode((string) base64_decode(strtr($c, '-_', '+/'), true), true);
        $this->assertIsArray($claims);
        $this->assertSame('https://push.example.net:8443', $claims['aud']);
        $this->assertSame('mailto:ops@example.com', $claims['sub']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 86400, $claims['exp']);

        $header = json_decode((string) base64_decode(strtr($h, '-_', '+/'), true), true);
        $this->assertIsArray($header);
        $this->assertSame('ES256', $header['alg']);
        $this->assertArrayNotHasKey('kid', $header);
    }

    public function testCustomTtlIsSent(): void
    {
        $transport = new FakeTransport(['*' => HttpResponse::fake('', 201)]);

        $this->driver($transport, ttl: 60)->send($this->subscription(), new PushMessage('T', 'B'));

        $this->assertSame('60', $transport->getRecorded()[0]['headers']['TTL']);
    }

    public function testGoneSubscriptionRaisesAPushExceptionNamingTheStatus(): void
    {
        $transport = new FakeTransport(['*' => HttpResponse::fake('gone', 410)]);

        $this->expectException(PushException::class);
        $this->expectExceptionMessageMatches('/410/');

        $this->driver($transport)->send($this->subscription(), new PushMessage('T', 'B'));
    }

    public function testRejectsATokenThatIsNotAJsonSubscription(): void
    {
        $this->expectException(PushException::class);

        $this->driver(new FakeTransport(['*' => HttpResponse::fake('', 201)]))->send('not-json', new PushMessage('T', 'B'));
    }

    public function testRejectsASubscriptionMissingKeys(): void
    {
        $this->expectException(PushException::class);

        $this->driver(new FakeTransport(['*' => HttpResponse::fake('', 201)]))
            ->send((string) json_encode(['endpoint' => 'https://push.example.net/x']), new PushMessage('T', 'B'));
    }

    public function testRejectsANonHttpsEndpoint(): void
    {
        $this->expectException(PushException::class);

        $this->driver(new FakeTransport(['*' => HttpResponse::fake('', 201)]))
            ->send($this->subscription('http://push.example.net/x'), new PushMessage('T', 'B'));
    }

    public function testVapidPublicKeyRejectsAnInvalidPrivateKey(): void
    {
        $this->expectException(PushException::class);

        WebPushDriver::vapidPublicKey('not a key');
    }
}

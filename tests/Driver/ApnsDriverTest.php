<?php

declare(strict_types=1);

namespace Tests\Push\Driver;

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\Push\Driver\ApnsDriver;
use EzPhp\Push\Jwt\Es256Signer;
use EzPhp\Push\PushException;
use EzPhp\Push\PushMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class ApnsDriverTest
 *
 * @package Tests\Push\Driver
 */
#[CoversClass(ApnsDriver::class)]
#[UsesClass(Es256Signer::class)]
final class ApnsDriverTest extends TestCase
{
    private string $privateKeyPem = '';

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
    }

    public function testSendPostsToProductionHostWithExpectedHeadersAndPayload(): void
    {
        $transport = new FakeTransport(['*' => HttpResponse::fake('', 200)]);
        $driver = $this->makeDriver($transport, sandbox: false);

        $driver->send('device-token', new PushMessage('Title', 'Body', ['order_id' => '42'], badge: 3, sound: 'default'));

        $recorded = $transport->getRecorded();
        $this->assertCount(1, $recorded);
        $this->assertSame('https://api.push.apple.com/3/device/device-token', $recorded[0]['url']);
        $this->assertSame('com.example.app', $recorded[0]['headers']['apns-topic']);
        $this->assertSame('alert', $recorded[0]['headers']['apns-push-type']);
        $this->assertStringStartsWith('bearer ', $recorded[0]['headers']['authorization']);

        /** @var array{aps: array{alert: array{title: string, body: string}, badge: int, sound: string}, order_id: string} $body */
        $body = json_decode($recorded[0]['body'], true);
        $this->assertSame('Title', $body['aps']['alert']['title']);
        $this->assertSame('Body', $body['aps']['alert']['body']);
        $this->assertSame(3, $body['aps']['badge']);
        $this->assertSame('default', $body['aps']['sound']);
        $this->assertSame('42', $body['order_id']);
    }

    public function testSendUsesSandboxHostWhenConfigured(): void
    {
        $transport = new FakeTransport(['*' => HttpResponse::fake('', 200)]);
        $driver = $this->makeDriver($transport, sandbox: true);

        $driver->send('device-token', new PushMessage('Title', 'Body'));

        $this->assertStringStartsWith(
            'https://api.sandbox.push.apple.com/',
            $transport->getRecorded()[0]['url'],
        );
    }

    public function testSendThrowsOnNonOkResponse(): void
    {
        $transport = new FakeTransport(['*' => HttpResponse::fake('{"reason":"BadDeviceToken"}', 400)]);
        $driver = $this->makeDriver($transport, sandbox: false);

        $this->expectException(PushException::class);
        $this->expectExceptionMessageMatches('/HTTP 400/');

        $driver->send('device-token', new PushMessage('Title', 'Body'));
    }

    public function testProviderTokenIsReusedAcrossRequests(): void
    {
        $transport = new FakeTransport(['*' => HttpResponse::fake('', 200)]);
        $driver = $this->makeDriver($transport, sandbox: false);

        $driver->send('token-a', new PushMessage('Title', 'Body'));
        $driver->send('token-b', new PushMessage('Title', 'Body'));

        $recorded = $transport->getRecorded();
        $this->assertSame($recorded[0]['headers']['authorization'], $recorded[1]['headers']['authorization']);
    }

    private function makeDriver(FakeTransport $transport, bool $sandbox): ApnsDriver
    {
        return new ApnsDriver(
            new HttpClient($transport),
            new Es256Signer($this->privateKeyPem, 'KEY123'),
            'TEAM123',
            'com.example.app',
            $sandbox,
        );
    }
}

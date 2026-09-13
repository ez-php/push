<?php

declare(strict_types=1);

namespace Tests\Push\Driver;

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\Push\Driver\FcmDriver;
use EzPhp\Push\Jwt\Rs256Signer;
use EzPhp\Push\PushException;
use EzPhp\Push\PushMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class FcmDriverTest
 *
 * @package Tests\Push\Driver
 */
#[CoversClass(FcmDriver::class)]
#[UsesClass(Rs256Signer::class)]
final class FcmDriverTest extends TestCase
{
    private string $privateKeyPem = '';

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
    }

    public function testSendExchangesTokenThenPostsNotification(): void
    {
        $transport = new FakeTransport([
            'https://oauth2.googleapis.com/*' => HttpResponse::fake(['access_token' => 'access-token-1', 'expires_in' => 3600], 200),
            'https://fcm.googleapis.com/*' => HttpResponse::fake('', 200),
        ]);
        $driver = $this->makeDriver($transport);

        $driver->send('device-token', new PushMessage('Title', 'Body', ['order_id' => '42']));

        $recorded = $transport->getRecorded();
        $this->assertCount(2, $recorded);

        $this->assertSame('https://oauth2.googleapis.com/token', $recorded[0]['url']);
        parse_str($recorded[0]['body'], $tokenRequestBody);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $tokenRequestBody['grant_type']);

        $this->assertSame(
            'https://fcm.googleapis.com/v1/projects/my-project/messages:send',
            $recorded[1]['url'],
        );
        $this->assertSame('Bearer access-token-1', $recorded[1]['headers']['authorization']);

        /** @var array{message: array{token: string, notification: array{title: string}, data: array{order_id: string}}} $body */
        $body = json_decode($recorded[1]['body'], true);
        $this->assertSame('device-token', $body['message']['token']);
        $this->assertSame('Title', $body['message']['notification']['title']);
        $this->assertSame('42', $body['message']['data']['order_id']);
    }

    public function testSendThrowsWhenTokenExchangeFails(): void
    {
        $transport = new FakeTransport([
            'https://oauth2.googleapis.com/*' => HttpResponse::fake('{"error":"invalid_grant"}', 401),
        ]);
        $driver = $this->makeDriver($transport);

        $this->expectException(PushException::class);
        $this->expectExceptionMessageMatches('/token exchange failed/');

        $driver->send('device-token', new PushMessage('Title', 'Body'));
    }

    public function testSendThrowsOnNonOkFcmResponse(): void
    {
        $transport = new FakeTransport([
            'https://oauth2.googleapis.com/*' => HttpResponse::fake(['access_token' => 'access-token-1', 'expires_in' => 3600], 200),
            'https://fcm.googleapis.com/*' => HttpResponse::fake('{"error":"NOT_FOUND"}', 404),
        ]);
        $driver = $this->makeDriver($transport);

        $this->expectException(PushException::class);
        $this->expectExceptionMessageMatches('/HTTP 404/');

        $driver->send('device-token', new PushMessage('Title', 'Body'));
    }

    public function testAccessTokenIsReusedAcrossRequests(): void
    {
        $transport = new FakeTransport([
            'https://oauth2.googleapis.com/*' => HttpResponse::fake(['access_token' => 'access-token-1', 'expires_in' => 3600], 200),
            'https://fcm.googleapis.com/*' => HttpResponse::fake('', 200),
        ]);
        $driver = $this->makeDriver($transport);

        $driver->send('token-a', new PushMessage('Title', 'Body'));
        $driver->send('token-b', new PushMessage('Title', 'Body'));

        $tokenExchanges = array_filter(
            $transport->getRecorded(),
            static fn (array $r): bool => $r['url'] === 'https://oauth2.googleapis.com/token',
        );

        $this->assertCount(1, $tokenExchanges);
    }

    private function makeDriver(FakeTransport $transport): FcmDriver
    {
        return new FcmDriver(
            new HttpClient($transport),
            new Rs256Signer($this->privateKeyPem),
            'my-project',
            'service@my-project.iam.gserviceaccount.com',
        );
    }
}

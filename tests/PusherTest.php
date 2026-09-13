<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Push\Driver\ArrayDriver;
use EzPhp\Push\Pusher;
use EzPhp\Push\PushException;
use EzPhp\Push\PushMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class PusherTest
 *
 * @package Tests
 */
#[CoversClass(Pusher::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(PushMessage::class)]
final class PusherTest extends TestCase
{
    public function testSendDelegatesToDriver(): void
    {
        $driver = new ArrayDriver();
        $pusher = new Pusher($driver);
        $message = new PushMessage('Title', 'Body');

        $pusher->send('token-1', $message);

        $this->assertSame([$message], $driver->sentTo('token-1'));
    }

    public function testSendToManyDeliversToEveryToken(): void
    {
        $driver = new ArrayDriver();
        $pusher = new Pusher($driver);
        $message = new PushMessage('Title', 'Body');

        $results = $pusher->sendToMany(['token-1', 'token-2'], $message);

        $this->assertSame([$message], $driver->sentTo('token-1'));
        $this->assertSame([$message], $driver->sentTo('token-2'));
        $this->assertNull($results['token-1']);
        $this->assertNull($results['token-2']);
    }

    public function testSendToManyIsolatesFailuresPerToken(): void
    {
        $driver = new class () implements \EzPhp\Push\PushDriverInterface {
            public function send(string $token, PushMessage $message): void
            {
                if ($token === 'bad-token') {
                    throw new PushException('rejected');
                }
            }
        };

        $pusher = new Pusher($driver);
        $results = $pusher->sendToMany(['good-token', 'bad-token'], new PushMessage('Title', 'Body'));

        $this->assertNull($results['good-token']);
        $this->assertInstanceOf(PushException::class, $results['bad-token']);
    }
}

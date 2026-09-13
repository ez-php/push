<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Push\Driver\ArrayDriver;
use EzPhp\Push\Push;
use EzPhp\Push\Pusher;
use EzPhp\Push\PushMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class PushTest
 *
 * @package Tests
 */
#[CoversClass(Push::class)]
#[UsesClass(Pusher::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(PushMessage::class)]
final class PushTest extends TestCase
{
    protected function tearDown(): void
    {
        Push::resetPusher();
        parent::tearDown();
    }

    public function testSendThrowsWhenPusherNotSet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pusher not set');

        Push::send('token', new PushMessage('Title', 'Body'));
    }

    public function testSendDelegatesToConfiguredPusher(): void
    {
        $driver = new ArrayDriver();
        Push::setPusher(new Pusher($driver));

        $message = new PushMessage('Title', 'Body');
        Push::send('token-1', $message);

        $this->assertSame([$message], $driver->sentTo('token-1'));
    }

    public function testSendToManyDelegatesToConfiguredPusher(): void
    {
        $driver = new ArrayDriver();
        Push::setPusher(new Pusher($driver));

        $message = new PushMessage('Title', 'Body');
        $results = Push::sendToMany(['token-1', 'token-2'], $message);

        $this->assertNull($results['token-1']);
        $this->assertNull($results['token-2']);
    }
}

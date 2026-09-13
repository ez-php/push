<?php

declare(strict_types=1);

namespace Tests\Push\Driver;

use EzPhp\Push\Driver\ArrayDriver;
use EzPhp\Push\PushMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class ArrayDriverTest
 *
 * @package Tests\Push\Driver
 */
#[CoversClass(ArrayDriver::class)]
final class ArrayDriverTest extends TestCase
{
    public function testSendStoresMessageOnToken(): void
    {
        $driver = new ArrayDriver();
        $message = new PushMessage('Title', 'Body');

        $driver->send('token-1', $message);

        $this->assertSame([$message], $driver->sentTo('token-1'));
    }

    public function testSentToReturnsEmptyArrayForUnknownToken(): void
    {
        $driver = new ArrayDriver();

        $this->assertSame([], $driver->sentTo('unknown-token'));
    }

    public function testMultipleMessagesToSameTokenAccumulate(): void
    {
        $driver = new ArrayDriver();
        $first = new PushMessage('First', 'Body');
        $second = new PushMessage('Second', 'Body');

        $driver->send('token-1', $first);
        $driver->send('token-1', $second);

        $this->assertSame([$first, $second], $driver->sentTo('token-1'));
    }

    public function testMessagesToDifferentTokensAreIsolated(): void
    {
        $driver = new ArrayDriver();
        $driver->send('token-a', new PushMessage('A', 'Body'));
        $driver->send('token-b', new PushMessage('B', 'Body'));

        $this->assertCount(1, $driver->sentTo('token-a'));
        $this->assertCount(1, $driver->sentTo('token-b'));
    }

    public function testResetClearsAllRecordedMessages(): void
    {
        $driver = new ArrayDriver();
        $driver->send('token-1', new PushMessage('Title', 'Body'));
        $driver->reset();

        $this->assertSame([], $driver->sentTo('token-1'));
    }
}

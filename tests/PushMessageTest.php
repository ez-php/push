<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Push\PushMessage;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class PushMessageTest
 *
 * @package Tests
 */
#[CoversClass(PushMessage::class)]
final class PushMessageTest extends TestCase
{
    public function testConstructorStoresAllFields(): void
    {
        $message = new PushMessage(
            title: 'Title',
            body: 'Body',
            data: ['order_id' => '42'],
            badge: 3,
            sound: 'default',
        );

        $this->assertSame('Title', $message->title);
        $this->assertSame('Body', $message->body);
        $this->assertSame(['order_id' => '42'], $message->data);
        $this->assertSame(3, $message->badge);
        $this->assertSame('default', $message->sound);
    }

    public function testOptionalFieldsDefaultToEmptyOrNull(): void
    {
        $message = new PushMessage(title: 'Title', body: 'Body');

        $this->assertSame([], $message->data);
        $this->assertNull($message->badge);
        $this->assertNull($message->sound);
    }
}

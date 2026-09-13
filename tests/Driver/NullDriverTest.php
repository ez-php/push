<?php

declare(strict_types=1);

namespace Tests\Push\Driver;

use EzPhp\Push\Driver\NullDriver;
use EzPhp\Push\PushMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class NullDriverTest
 *
 * @package Tests\Push\Driver
 */
#[CoversClass(NullDriver::class)]
final class NullDriverTest extends TestCase
{
    public function testSendIsANoOp(): void
    {
        $driver = new NullDriver();

        $driver->send('token', new PushMessage('Title', 'Body'));

        $this->addToAssertionCount(1);
    }
}

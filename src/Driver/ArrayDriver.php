<?php

declare(strict_types=1);

namespace EzPhp\Push\Driver;

use EzPhp\Push\PushDriverInterface;
use EzPhp\Push\PushMessage;

/**
 * Class ArrayDriver
 *
 * Stores sent push notifications in-memory, grouped by device token.
 * Designed for assertions in unit and feature tests — inspect sent
 * notifications without any network side-effects.
 *
 * Usage:
 *   $driver = new ArrayDriver();
 *   Push::setPusher(new Pusher($driver));
 *   // ... exercise code under test ...
 *   $messages = $driver->sentTo('device-token');
 *   $this->assertCount(1, $messages);
 *
 * @package EzPhp\Push\Driver
 */
final class ArrayDriver implements PushDriverInterface
{
    /**
     * @var array<string, list<PushMessage>>
     */
    private array $sent = [];

    /**
     * @param string      $token
     * @param PushMessage $message
     *
     * @return void
     */
    public function send(string $token, PushMessage $message): void
    {
        $this->sent[$token][] = $message;
    }

    /**
     * Return all messages sent to the given device token.
     *
     * @param string $token
     *
     * @return list<PushMessage>
     */
    public function sentTo(string $token): array
    {
        return $this->sent[$token] ?? [];
    }

    /**
     * Clear all recorded messages.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->sent = [];
    }
}

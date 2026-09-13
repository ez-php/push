<?php

declare(strict_types=1);

namespace EzPhp\Push\Driver;

use EzPhp\Push\PushDriverInterface;
use EzPhp\Push\PushMessage;

/**
 * Class NullDriver
 *
 * Silently discards all push notifications. Use as the default driver in
 * environments with no configured APNS/FCM credentials, or in tests where
 * push side-effects are irrelevant.
 *
 * @package EzPhp\Push\Driver
 */
final class NullDriver implements PushDriverInterface
{
    /**
     * @param string      $token
     * @param PushMessage $message
     *
     * @return void
     */
    public function send(string $token, PushMessage $message): void
    {
        // intentional no-op
    }
}

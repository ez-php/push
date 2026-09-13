<?php

declare(strict_types=1);

namespace EzPhp\Push;

/**
 * Class PushException
 *
 * Base exception for all push-notification errors: invalid driver
 * configuration, a malformed signing key, or a non-2xx response from
 * APNS/FCM.
 *
 * @package EzPhp\Push
 */
final class PushException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace EzPhp\Push;

/**
 * Interface PushDriverInterface
 *
 * Contract for push-notification delivery drivers (APNS, FCM, Null, Array).
 * A driver delivers exactly one message to exactly one device token; fan-out
 * to multiple tokens is the Pusher's responsibility.
 *
 * @package EzPhp\Push
 */
interface PushDriverInterface
{
    /**
     * Deliver a push notification to one device token.
     *
     * @param string       $token   Device/registration token (APNS device token or FCM registration token).
     * @param PushMessage  $message The notification to deliver.
     *
     * @throws PushException When the underlying transport or provider rejects the notification.
     *
     * @return void
     */
    public function send(string $token, PushMessage $message): void;
}

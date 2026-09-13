<?php

declare(strict_types=1);

namespace EzPhp\Push;

/**
 * Class PushMessage
 *
 * Immutable value object describing one mobile push notification, mapped
 * onto each driver's wire format (APNS `aps` dictionary, FCM `notification`
 * object).
 *
 * @package EzPhp\Push
 */
final readonly class PushMessage
{
    /**
     * PushMessage Constructor
     *
     * @param string                $title Notification title.
     * @param string                $body  Notification body text.
     * @param array<string, string> $data  Custom key/value payload delivered alongside the alert.
     * @param int|null              $badge App icon badge count (APNS only; ignored by FCM).
     * @param string|null           $sound Sound file name to play, or null for silent delivery.
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public ?int $badge = null,
        public ?string $sound = null,
    ) {
    }
}

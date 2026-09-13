<?php

declare(strict_types=1);

namespace EzPhp\Push;

/**
 * Class Pusher
 *
 * Orchestrates delivery of a PushMessage to one or many device tokens via
 * the configured PushDriverInterface.
 *
 * @package EzPhp\Push
 */
final class Pusher
{
    /**
     * Pusher Constructor
     *
     * @param PushDriverInterface $driver
     */
    public function __construct(private readonly PushDriverInterface $driver)
    {
    }

    /**
     * Deliver a push notification to a single device token.
     *
     * @param string      $token
     * @param PushMessage $message
     *
     * @throws PushException When delivery fails.
     *
     * @return void
     */
    public function send(string $token, PushMessage $message): void
    {
        $this->driver->send($token, $message);
    }

    /**
     * Deliver a push notification to many device tokens.
     *
     * Each token is attempted independently — a failure on one token does not
     * stop delivery to the others. Callers that need to prune invalid tokens
     * should inspect the returned map (device-token storage itself is an
     * application-layer concern).
     *
     * @param list<string> $tokens
     * @param PushMessage  $message
     *
     * @return array<string, PushException|null> Map of token => exception (null on success).
     */
    public function sendToMany(array $tokens, PushMessage $message): array
    {
        $results = [];

        foreach ($tokens as $token) {
            try {
                $this->driver->send($token, $message);
                $results[$token] = null;
            } catch (PushException $exception) {
                $results[$token] = $exception;
            }
        }

        return $results;
    }
}

<?php

declare(strict_types=1);

namespace EzPhp\Push;

use Closure;
use RuntimeException;

/**
 * Class Push
 *
 * Static facade for the Pusher singleton.
 * Call setPusher() before using any static methods, or setResolver() to defer
 * resolution until first use (done automatically by
 * PushServiceProvider::boot(), so that registering the provider does not
 * force PushDriverInterface's container binding to resolve — and be cached
 * as a singleton — before the application has finished configuring it, e.g.
 * before test code has set PUSH_DRIVER for that test case). Throws
 * RuntimeException if called before either is set — fail-fast prevents
 * silent discards.
 *
 * Global state is intentional and documented — the facade allows
 * Push::send() from anywhere without container access.
 *
 * @package EzPhp\Push
 */
final class Push
{
    /**
     * @var Pusher|null
     */
    private static ?Pusher $pusher = null;

    /**
     * @var (Closure(): Pusher)|null
     */
    private static ?Closure $resolver = null;

    /**
     * Set the underlying Pusher instance directly.
     *
     * @param Pusher $pusher
     *
     * @return void
     */
    public static function setPusher(Pusher $pusher): void
    {
        self::$pusher = $pusher;
    }

    /**
     * Defer resolution of the underlying Pusher until the first actual
     * facade call, instead of resolving it immediately.
     *
     * @param Closure(): Pusher $resolver
     *
     * @return void
     */
    public static function setResolver(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Reset the Pusher singleton and resolver to null.
     *
     * Call this in setUp()/tearDown() of any test that touches the Push facade.
     *
     * @return void
     */
    public static function resetPusher(): void
    {
        self::$pusher = null;
        self::$resolver = null;
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
    public static function send(string $token, PushMessage $message): void
    {
        self::pusher()->send($token, $message);
    }

    /**
     * Deliver a push notification to many device tokens.
     *
     * @param list<string> $tokens
     * @param PushMessage  $message
     *
     * @return array<string, PushException|null> Map of token => exception (null on success).
     */
    public static function sendToMany(array $tokens, PushMessage $message): array
    {
        return self::pusher()->sendToMany($tokens, $message);
    }

    /**
     * Return the current Pusher instance, resolving it from the deferred
     * resolver (set via setResolver()) on first access, or throw if neither
     * an instance nor a resolver has been set.
     *
     * @return Pusher
     */
    private static function pusher(): Pusher
    {
        if (self::$pusher !== null) {
            return self::$pusher;
        }

        if (self::$resolver !== null) {
            self::$pusher = (self::$resolver)();

            return self::$pusher;
        }

        throw new RuntimeException(
            'Pusher not set. Register PushServiceProvider or call Push::setPusher().'
        );
    }
}

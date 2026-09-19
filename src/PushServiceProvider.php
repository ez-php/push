<?php

declare(strict_types=1);

namespace EzPhp\Push;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\HttpClient\HttpClient;
use EzPhp\Push\Driver\ApnsDriver;
use EzPhp\Push\Driver\ArrayDriver;
use EzPhp\Push\Driver\FcmDriver;
use EzPhp\Push\Driver\NullDriver;
use EzPhp\Push\Driver\WebPushDriver;
use EzPhp\Push\Jwt\Es256Signer;
use EzPhp\Push\Jwt\Rs256Signer;
use EzPhp\Push\WebPush\WebPushEncryptor;

/**
 * Class PushServiceProvider
 *
 * Binds the PushDriverInterface and Pusher to the container, then wires the
 * Push static facade in boot().
 *
 * Requires HttpClientServiceProvider (ez-php/http-client) to be registered
 * before this provider — the apns/fcm drivers resolve HttpClient from the
 * container.
 *
 * Configuration keys (in config/push.php or environment):
 *   - push.driver             — "null" (default) | "array" | "apns" | "fcm" | "webpush"
 *   - push.apns.key_id        — APNS auth-key ID (the `kid` claim)
 *   - push.apns.team_id       — Apple Developer Team ID (the `iss` claim)
 *   - push.apns.bundle_id     — app bundle identifier (the `apns-topic` header)
 *   - push.apns.private_key   — PEM contents of the .p8 auth key
 *   - push.apns.sandbox       — use the sandbox APNS host (default: false)
 *   - push.fcm.project_id     — Firebase project ID
 *   - push.fcm.client_email   — service-account client email (the `iss` claim)
 *   - push.fcm.private_key    — PEM contents of the service-account private key
 *   - push.webpush.private_key — PEM contents of the VAPID P-256 private key
 *   - push.webpush.subject    — VAPID contact (`mailto:` or `https:` URL)
 *   - push.webpush.ttl        — seconds a push service retains an undelivered message (default: 86400)
 *
 * @package EzPhp\Push
 */
final class PushServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(
            PushDriverInterface::class,
            function (ContainerInterface $app): PushDriverInterface {
                $config = $app->make(ConfigInterface::class);
                $raw = $config->get('push.driver', 'null');
                $driver = is_string($raw) ? $raw : 'null';

                return match ($driver) {
                    'array' => new ArrayDriver(),
                    'apns' => $this->createApnsDriver($app, $config),
                    'fcm' => $this->createFcmDriver($app, $config),
                    'webpush' => $this->createWebPushDriver($app, $config),
                    default => new NullDriver(),
                };
            }
        );

        $this->app->bind(
            Pusher::class,
            function (ContainerInterface $app): Pusher {
                return new Pusher($app->make(PushDriverInterface::class));
            }
        );
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        Push::setResolver(fn (): Pusher => $this->app->make(Pusher::class));
    }

    /**
     * @param ContainerInterface $app
     * @param ConfigInterface    $config
     *
     * @return ApnsDriver
     */
    private function createApnsDriver(ContainerInterface $app, ConfigInterface $config): ApnsDriver
    {
        /** @var HttpClient $client */
        $client = $app->make(HttpClient::class);

        $privateKey = $this->configString($config, 'push.apns.private_key');
        $keyId = $this->configString($config, 'push.apns.key_id');
        $teamId = $this->configString($config, 'push.apns.team_id');
        $bundleId = $this->configString($config, 'push.apns.bundle_id');
        $sandbox = (bool) $config->get('push.apns.sandbox', false);

        return new ApnsDriver($client, new Es256Signer($privateKey, $keyId), $teamId, $bundleId, $sandbox);
    }

    /**
     * @param ContainerInterface $app
     * @param ConfigInterface    $config
     *
     * @return FcmDriver
     */
    private function createFcmDriver(ContainerInterface $app, ConfigInterface $config): FcmDriver
    {
        /** @var HttpClient $client */
        $client = $app->make(HttpClient::class);

        $privateKey = $this->configString($config, 'push.fcm.private_key');
        $projectId = $this->configString($config, 'push.fcm.project_id');
        $clientEmail = $this->configString($config, 'push.fcm.client_email');

        return new FcmDriver($client, new Rs256Signer($privateKey), $projectId, $clientEmail);
    }

    /**
     * @param ContainerInterface $app
     * @param ConfigInterface    $config
     *
     * @return WebPushDriver
     */
    private function createWebPushDriver(ContainerInterface $app, ConfigInterface $config): WebPushDriver
    {
        /** @var HttpClient $client */
        $client = $app->make(HttpClient::class);

        $privateKey = $this->configString($config, 'push.webpush.private_key');
        $subject = $this->configString($config, 'push.webpush.subject');
        $ttl = $config->get('push.webpush.ttl', 86_400);

        return new WebPushDriver(
            $client,
            new Es256Signer($privateKey),
            WebPushDriver::vapidPublicKey($privateKey),
            $subject,
            new WebPushEncryptor(),
            is_int($ttl) && $ttl >= 0 ? $ttl : 86_400,
        );
    }

    /**
     * @param ConfigInterface $config
     * @param string          $key
     *
     * @return string
     */
    private function configString(ConfigInterface $config, string $key): string
    {
        $value = $config->get($key, '');

        return is_string($value) ? $value : '';
    }
}

<?php

declare(strict_types=1);

namespace EzPhp\Push\Driver;

use EzPhp\HttpClient\HttpClient;
use EzPhp\Push\Jwt\Es256Signer;
use EzPhp\Push\PushDriverInterface;
use EzPhp\Push\PushException;
use EzPhp\Push\PushMessage;
use EzPhp\Push\WebPush\WebPushEncryptor;

/**
 * Class WebPushDriver
 *
 * Delivers browser push notifications through the W3C Push API: the payload
 * is encrypted for the subscription (RFC 8291, see {@see WebPushEncryptor})
 * and POSTed to the subscription's push-service endpoint, authenticated with
 * VAPID (RFC 8292) — an ES256 JWT over the endpoint origin plus the
 * application server's public key.
 *
 * The "device token" this driver takes is the browser's `PushSubscription`
 * serialised as JSON (`JSON.stringify(subscription)`):
 * `{"endpoint": "...", "keys": {"p256dh": "...", "auth": "..."}}`.
 * The message is delivered to the service worker as a JSON object with
 * `title`, `body`, `data` and — when set — `badge` and `sound`.
 *
 * Storing subscriptions and pruning gone ones (HTTP 404/410 raise a
 * PushException naming the status) are application-layer concerns.
 *
 * @package EzPhp\Push\Driver
 */
final class WebPushDriver implements PushDriverInterface
{
    /**
     * VAPID JWT lifetime; RFC 8292 caps `exp` at 24 hours.
     */
    private const int JWT_TTL_SECONDS = 43_200;

    /**
     * WebPushDriver Constructor
     *
     * @param HttpClient       $client          HTTP client used to reach the push service.
     * @param Es256Signer      $signer          Signs VAPID JWTs (built from the VAPID private key, without a key id).
     * @param string           $vapidPublicKey  The VAPID public key as base64url (see {@see self::vapidPublicKey()}).
     * @param string           $subject         VAPID `sub`: a `mailto:` or `https:` contact for the push service operator.
     * @param WebPushEncryptor $encryptor       Payload encryptor.
     * @param int              $ttl             Seconds the push service should retain an undelivered message.
     */
    public function __construct(
        private readonly HttpClient $client,
        private readonly Es256Signer $signer,
        private readonly string $vapidPublicKey,
        private readonly string $subject,
        private readonly WebPushEncryptor $encryptor = new WebPushEncryptor(),
        private readonly int $ttl = 86_400,
    ) {
    }

    /**
     * Derive the VAPID public key (base64url, 65-byte uncompressed point) from the
     * private key PEM. This is also the `applicationServerKey` a browser needs for
     * `pushManager.subscribe()`.
     *
     * @param string $privateKeyPem PEM-encoded P-256 private key.
     *
     * @throws PushException When the key is not a usable EC key.
     *
     * @return string
     */
    public static function vapidPublicKey(string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new PushException('Invalid VAPID private key: ' . (openssl_error_string() ?: 'not an EC key'));
        }

        $point = "\x04"
            . str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return self::base64UrlEncode($point);
    }

    /**
     * {@inheritDoc}
     */
    public function send(string $token, PushMessage $message): void
    {
        [$endpoint, $uaPublicKey, $authSecret] = $this->parseSubscription($token);

        $body = $this->encryptor->encrypt($this->buildPayload($message), $uaPublicKey, $authSecret);

        $response = $this->client
            ->post($endpoint)
            ->withHeaders([
                'Authorization' => 'vapid t=' . $this->vapidJwt($endpoint) . ', k=' . $this->vapidPublicKey,
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string) $this->ttl,
            ])
            ->withBody($body)
            ->send();

        if (!$response->ok()) {
            throw new PushException(sprintf(
                'Web Push service rejected push to %s (HTTP %d): %s',
                $endpoint,
                $response->status(),
                $response->body(),
            ));
        }
    }

    /**
     * @param PushMessage $message
     *
     * @return string JSON payload for the service worker.
     */
    private function buildPayload(PushMessage $message): string
    {
        $payload = ['title' => $message->title, 'body' => $message->body, 'data' => $message->data];

        if ($message->badge !== null) {
            $payload['badge'] = $message->badge;
        }

        if ($message->sound !== null) {
            $payload['sound'] = $message->sound;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $endpoint
     *
     * @return string
     */
    private function vapidJwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $this->signer->sign([
            'aud' => $audience,
            'exp' => time() + self::JWT_TTL_SECONDS,
            'sub' => $this->subject,
        ]);
    }

    /**
     * @param string $token JSON-serialised PushSubscription.
     *
     * @throws PushException When the subscription is malformed.
     *
     * @return array{0: non-empty-string, 1: string, 2: string} Endpoint, raw p256dh key, raw auth secret.
     */
    private function parseSubscription(string $token): array
    {
        $data = json_decode($token, true);

        if (!is_array($data)) {
            throw new PushException('Web Push token must be a JSON-serialised PushSubscription.');
        }

        $endpoint = $data['endpoint'] ?? null;
        $keys = $data['keys'] ?? null;
        $p256dh = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
        $auth = is_array($keys) ? ($keys['auth'] ?? null) : null;

        if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)) {
            throw new PushException('Web Push subscription requires "endpoint", "keys.p256dh" and "keys.auth".');
        }

        if (!str_starts_with($endpoint, 'https://')) {
            throw new PushException('Web Push endpoint must use https.');
        }

        return [$endpoint, self::base64UrlDecode($p256dh), self::base64UrlDecode($auth)];
    }

    /**
     * @param string $data
     *
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param string $data
     *
     * @return string
     */
    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), false);
    }
}

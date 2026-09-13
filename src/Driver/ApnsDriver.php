<?php

declare(strict_types=1);

namespace EzPhp\Push\Driver;

use EzPhp\HttpClient\HttpClient;
use EzPhp\Push\Jwt\Es256Signer;
use EzPhp\Push\PushDriverInterface;
use EzPhp\Push\PushException;
use EzPhp\Push\PushMessage;

/**
 * Class ApnsDriver
 *
 * Delivers push notifications via Apple's HTTP/2 provider API, authenticated
 * with a token-based (.p8) provider-authentication JWT (ES256).
 *
 * The provider JWT is reused across requests — Apple recommends signing at
 * most once every 20 minutes and accepts tokens up to 1 hour old — so it is
 * cached in-memory and re-signed once it nears TOKEN_TTL_SECONDS old.
 *
 * HTTP/2 itself is negotiated by the underlying transport (cURL); this
 * driver does not force the protocol version.
 *
 * Device-token storage, pruning of tokens APNS reports as unregistered
 * (HTTP 410), and topic/channel management are application-layer concerns —
 * out of scope here.
 *
 * @package EzPhp\Push\Driver
 */
final class ApnsDriver implements PushDriverInterface
{
    private const string PRODUCTION_HOST = 'https://api.push.apple.com';

    private const string SANDBOX_HOST = 'https://api.sandbox.push.apple.com';

    /**
     * Reuse a signed provider token for up to 50 minutes (Apple tokens are
     * valid up to 1 hour; this leaves margin for clock drift).
     */
    private const int TOKEN_TTL_SECONDS = 3000;

    private ?string $cachedToken = null;

    private int $cachedTokenIssuedAt = 0;

    /**
     * ApnsDriver Constructor
     *
     * @param HttpClient  $client   HTTP client used to reach APNS.
     * @param Es256Signer $signer   Signs provider-authentication JWTs with the APNS .p8 key.
     * @param string      $teamId   Apple Developer Team ID (the `iss` claim).
     * @param string      $bundleId App bundle identifier (the `apns-topic` header).
     * @param bool        $sandbox  Use the sandbox APNS host instead of production.
     */
    public function __construct(
        private readonly HttpClient $client,
        private readonly Es256Signer $signer,
        private readonly string $teamId,
        private readonly string $bundleId,
        private readonly bool $sandbox = false,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function send(string $token, PushMessage $message): void
    {
        $host = $this->sandbox ? self::SANDBOX_HOST : self::PRODUCTION_HOST;

        $response = $this->client
            ->post($host . '/3/device/' . $token)
            ->withHeaders([
                'authorization' => 'bearer ' . $this->providerToken(),
                'apns-topic' => $this->bundleId,
                'apns-push-type' => 'alert',
            ])
            ->withJson($this->buildPayload($message))
            ->send();

        if (!$response->ok()) {
            throw new PushException(sprintf(
                'APNS rejected push to %s (HTTP %d): %s',
                $token,
                $response->status(),
                $response->body(),
            ));
        }
    }

    /**
     * Build the APNS JSON payload: the `aps` dictionary plus custom data at
     * the top level, per Apple's payload format.
     *
     * @param PushMessage $message
     *
     * @return array<string, mixed>
     */
    private function buildPayload(PushMessage $message): array
    {
        $aps = [
            'alert' => [
                'title' => $message->title,
                'body' => $message->body,
            ],
        ];

        if ($message->badge !== null) {
            $aps['badge'] = $message->badge;
        }

        if ($message->sound !== null) {
            $aps['sound'] = $message->sound;
        }

        return [...$message->data, 'aps' => $aps];
    }

    /**
     * Return a cached provider-authentication JWT, re-signing it once it is
     * older than TOKEN_TTL_SECONDS.
     *
     * @return string
     */
    private function providerToken(): string
    {
        $now = time();

        if ($this->cachedToken !== null && ($now - $this->cachedTokenIssuedAt) < self::TOKEN_TTL_SECONDS) {
            return $this->cachedToken;
        }

        $this->cachedToken = $this->signer->sign(['iss' => $this->teamId, 'iat' => $now]);
        $this->cachedTokenIssuedAt = $now;

        return $this->cachedToken;
    }
}

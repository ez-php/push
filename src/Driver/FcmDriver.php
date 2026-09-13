<?php

declare(strict_types=1);

namespace EzPhp\Push\Driver;

use EzPhp\HttpClient\HttpClient;
use EzPhp\Push\Jwt\Rs256Signer;
use EzPhp\Push\PushDriverInterface;
use EzPhp\Push\PushException;
use EzPhp\Push\PushMessage;

/**
 * Class FcmDriver
 *
 * Delivers push notifications via Firebase Cloud Messaging's HTTP v1 API,
 * authenticated with a Google service-account OAuth2 access token obtained
 * through the JWT-bearer grant (RS256-signed assertion).
 *
 * The access token is cached in-memory and refreshed shortly before its
 * 1-hour Google-issued lifetime expires.
 *
 * Device-token (registration-token) storage and FCM topic subscription
 * management are application-layer concerns — out of scope here.
 *
 * @package EzPhp\Push\Driver
 */
final class FcmDriver implements PushDriverInterface
{
    private const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * Google access tokens are valid for 3600 seconds; refresh a little early.
     */
    private const int ACCESS_TOKEN_TTL_SECONDS = 3300;

    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenIssuedAt = 0;

    /**
     * FcmDriver Constructor
     *
     * @param HttpClient  $client      HTTP client used to reach the OAuth2 and FCM endpoints.
     * @param Rs256Signer $signer      Signs the OAuth2 JWT-bearer assertion with the service-account key.
     * @param string      $projectId   Firebase project ID.
     * @param string      $clientEmail Service-account client email (the `iss` claim).
     */
    public function __construct(
        private readonly HttpClient $client,
        private readonly Rs256Signer $signer,
        private readonly string $projectId,
        private readonly string $clientEmail,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function send(string $token, PushMessage $message): void
    {
        $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->projectId);

        $response = $this->client
            ->post($url)
            ->withHeaders(['authorization' => 'Bearer ' . $this->accessToken()])
            ->withJson([
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $message->title,
                        'body' => $message->body,
                    ],
                    'data' => $message->data,
                ],
            ])
            ->send();

        if (!$response->ok()) {
            throw new PushException(sprintf(
                'FCM rejected push to %s (HTTP %d): %s',
                $token,
                $response->status(),
                $response->body(),
            ));
        }
    }

    /**
     * Return a cached OAuth2 access token, exchanging a fresh JWT-bearer
     * assertion once the cached one nears expiry.
     *
     * @throws PushException When the token exchange fails or returns an unexpected response.
     *
     * @return string
     */
    private function accessToken(): string
    {
        $now = time();

        if ($this->cachedAccessToken !== null && ($now - $this->cachedAccessTokenIssuedAt) < self::ACCESS_TOKEN_TTL_SECONDS) {
            return $this->cachedAccessToken;
        }

        $assertion = $this->signer->sign([
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_ENDPOINT,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $response = $this->client
            ->post(self::TOKEN_ENDPOINT)
            ->withForm([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])
            ->send();

        if (!$response->ok()) {
            throw new PushException(sprintf(
                'FCM OAuth2 token exchange failed (HTTP %d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        /** @var mixed $decoded */
        $decoded = $response->json();

        if (!is_array($decoded) || !isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new PushException('FCM OAuth2 token exchange returned an unexpected response.');
        }

        $this->cachedAccessToken = $decoded['access_token'];
        $this->cachedAccessTokenIssuedAt = $now;

        return $this->cachedAccessToken;
    }
}

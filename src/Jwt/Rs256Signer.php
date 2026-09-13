<?php

declare(strict_types=1);

namespace EzPhp\Push\Jwt;

use EzPhp\Push\PushException;

/**
 * Class Rs256Signer
 *
 * Signs compact JWTs with RS256 (RSASSA-PKCS1-v1_5 + SHA-256), the algorithm
 * Google service-account OAuth2 JWT-bearer assertions require. Not a
 * general-purpose JWT library — scoped to exactly what FcmDriver needs.
 *
 * Unlike ECDSA, `openssl_sign()` already produces the raw PKCS#1 v1.5
 * signature bytes JWS expects, so no DER re-packing is needed here.
 *
 * @package EzPhp\Push\Jwt
 */
final class Rs256Signer
{
    /**
     * Rs256Signer Constructor
     *
     * @param string $privateKeyPem PEM-encoded RSA private key (Google service-account key).
     */
    public function __construct(private readonly string $privateKeyPem)
    {
    }

    /**
     * Sign the given claims and return a compact JWT string.
     *
     * @param array<string, int|string> $claims
     *
     * @throws PushException When the key is invalid or signing fails.
     *
     * @return string
     */
    public function sign(array $claims): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $signingInput = self::base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . self::base64UrlEncode((string) json_encode($claims, JSON_THROW_ON_ERROR));

        $key = openssl_pkey_get_private($this->privateKeyPem);

        if ($key === false) {
            throw new PushException('Invalid FCM service-account private key: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $signature = '';

        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PushException('Failed to sign FCM JWT: ' . (openssl_error_string() ?: 'unknown error'));
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
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
}

<?php

declare(strict_types=1);

namespace EzPhp\Push\Jwt;

use EzPhp\Push\PushException;

/**
 * Class Es256Signer
 *
 * Signs compact JWTs with ES256 (ECDSA P-256 + SHA-256), the algorithm APNS
 * provider-authentication tokens require. Not a general-purpose JWT library —
 * scoped to exactly what ApnsDriver needs.
 *
 * ext-openssl produces ECDSA signatures in ASN.1 DER form; JWS requires the
 * raw, fixed-width R||S concatenation instead, so the DER structure is
 * parsed and re-packed by hand.
 *
 * @package EzPhp\Push\Jwt
 */
final class Es256Signer
{
    /**
     * Size in bytes of each of the R and S components for a P-256 curve.
     */
    private const int COMPONENT_LENGTH = 32;

    /**
     * Es256Signer Constructor
     *
     * @param string $privateKeyPem PEM-encoded EC private key (APNS .p8 auth key contents).
     * @param string $keyId         APNS key ID (the `kid` header claim).
     */
    public function __construct(
        private readonly string $privateKeyPem,
        private readonly string $keyId,
    ) {
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
        $header = ['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $this->keyId];

        $signingInput = self::base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . self::base64UrlEncode((string) json_encode($claims, JSON_THROW_ON_ERROR));

        $key = openssl_pkey_get_private($this->privateKeyPem);

        if ($key === false) {
            throw new PushException('Invalid APNS private key: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $der = '';

        if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new PushException('Failed to sign APNS JWT: ' . (openssl_error_string() ?: 'unknown error'));
        }

        return $signingInput . '.' . self::base64UrlEncode($this->derToRaw($der));
    }

    /**
     * Convert an ASN.1 DER-encoded ECDSA signature (SEQUENCE of two INTEGERs)
     * into the raw, fixed-width R||S concatenation JWS expects.
     *
     * @param string $der
     *
     * @throws PushException When the signature is not well-formed DER.
     *
     * @return string
     */
    private function derToRaw(string $der): string
    {
        $offset = 0;

        if (($der[$offset] ?? '') !== "\x30") {
            throw new PushException('Malformed ECDSA signature: expected DER SEQUENCE.');
        }

        $offset++;
        $offset += $this->derLengthByteCount($der, $offset);

        $r = $this->readDerInteger($der, $offset);
        $s = $this->readDerInteger($der, $offset);

        return str_pad($r, self::COMPONENT_LENGTH, "\x00", STR_PAD_LEFT)
            . str_pad($s, self::COMPONENT_LENGTH, "\x00", STR_PAD_LEFT);
    }

    /**
     * Return how many bytes the DER length field at $offset occupies, without
     * needing its decoded value (the two INTEGERs are read independently).
     *
     * @param string $der
     * @param int    $offset
     *
     * @return int
     */
    private function derLengthByteCount(string $der, int $offset): int
    {
        $first = ord($der[$offset] ?? "\x00");

        return ($first & 0x80) === 0 ? 1 : 1 + ($first & 0x7f);
    }

    /**
     * Read one ASN.1 DER INTEGER starting at $offset, advance $offset past
     * it, and return its big-endian bytes with any DER sign-padding stripped.
     *
     * @param string $der
     * @param int    &$offset
     *
     * @throws PushException When the tag is not INTEGER.
     *
     * @return string
     */
    private function readDerInteger(string $der, int &$offset): string
    {
        if (($der[$offset] ?? '') !== "\x02") {
            throw new PushException('Malformed ECDSA signature: expected DER INTEGER.');
        }

        $offset++;
        $len = ord($der[$offset] ?? "\x00");
        $offset++;

        $bytes = substr($der, $offset, $len);
        $offset += $len;

        return ltrim($bytes, "\x00");
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

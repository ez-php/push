<?php

declare(strict_types=1);

namespace EzPhp\Push\WebPush;

use EzPhp\Push\PushException;

/**
 * Class WebPushEncryptor
 *
 * Encrypts a Web Push payload for one subscription with the `aes128gcm`
 * content coding of RFC 8291 (message encryption for Web Push, on top of
 * RFC 8188): an ephemeral ECDH P-256 exchange with the browser's `p256dh`
 * key, HKDF-SHA-256 key derivation mixed with the subscription's `auth`
 * secret, and AES-128-GCM. Built on ext-openssl and `hash_hkdf()` only.
 *
 * Single-record only: the plaintext must fit one 4096-byte record (max 4079
 * bytes), which is also the minimum every push service must accept.
 *
 * @package EzPhp\Push\WebPush
 */
final class WebPushEncryptor
{
    private const int RECORD_SIZE = 4096;

    private const int MAX_PLAINTEXT = 4079;

    private const string EC_PRIVATE_KEY_PREFIX = "\x30\x31\x02\x01\x01\x04\x20";

    private const string EC_PRIVATE_KEY_SUFFIX = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

    private const string SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    /**
     * Encrypt `$plaintext` into a complete `aes128gcm` request body
     * (header block followed by the encrypted record).
     *
     * `$senderPrivateKey` and `$salt` exist only so the RFC 8291 Appendix A
     * test vector can be reproduced deterministically; production callers
     * omit both and get a fresh ephemeral key and random salt per message —
     * reusing either would break the scheme's security.
     *
     * @param string      $plaintext         Payload bytes (max 4079).
     * @param string      $uaPublicKey       Subscription `p256dh`: 65-byte uncompressed P-256 point, raw bytes.
     * @param string      $authSecret        Subscription `auth`: 16 raw bytes.
     * @param string|null $senderPrivateKey  Raw 32-byte ephemeral private scalar (tests only).
     * @param string|null $salt              Raw 16-byte salt (tests only).
     *
     * @throws PushException On malformed keys, an oversized payload, or an OpenSSL failure.
     *
     * @return string
     */
    public function encrypt(
        string $plaintext,
        string $uaPublicKey,
        string $authSecret,
        ?string $senderPrivateKey = null,
        ?string $salt = null,
    ): string {
        if (strlen($uaPublicKey) !== 65 || $uaPublicKey[0] !== "\x04") {
            throw new PushException('Web Push p256dh key must be a 65-byte uncompressed P-256 point.');
        }

        if (strlen($authSecret) !== 16) {
            throw new PushException('Web Push auth secret must be 16 bytes.');
        }

        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new PushException('Web Push payload exceeds the single-record limit of ' . self::MAX_PLAINTEXT . ' bytes.');
        }

        $salt ??= random_bytes(16);
        [$senderKey, $senderPublic] = $this->senderKey($senderPrivateKey);

        $uaKey = openssl_pkey_get_public($this->pem('PUBLIC KEY', self::SPKI_PREFIX . $uaPublicKey));

        if ($uaKey === false) {
            throw new PushException('Invalid Web Push p256dh key: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $ecdhSecret = openssl_pkey_derive($uaKey, $senderKey);

        if ($ecdhSecret === false) {
            throw new PushException('Web Push ECDH failed: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $keyInfo = "WebPush: info\x00" . $uaPublicKey . $senderPublic;
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if ($ciphertext === false) {
            throw new PushException('Web Push encryption failed: ' . (openssl_error_string() ?: 'unknown error'));
        }

        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $senderPublic . $ciphertext . $tag;
    }

    /**
     * Load (or generate) the ephemeral sender key pair.
     *
     * @param string|null $rawPrivate
     *
     * @throws PushException
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string} The key and its 65-byte uncompressed public point.
     */
    private function senderKey(?string $rawPrivate): array
    {
        if ($rawPrivate === null) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        } else {
            $key = openssl_pkey_get_private($this->pem('EC PRIVATE KEY', self::EC_PRIVATE_KEY_PREFIX . $rawPrivate . self::EC_PRIVATE_KEY_SUFFIX));
        }

        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($key === false || $details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new PushException('Failed to load the Web Push sender key: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $x = str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return [$key, "\x04" . $x . $y];
    }

    /**
     * @param string $label
     * @param string $der
     *
     * @return string
     */
    private function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }
}

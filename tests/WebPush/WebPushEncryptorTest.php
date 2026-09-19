<?php

declare(strict_types=1);

namespace Tests\Push\WebPush;

use EzPhp\Push\PushException;
use EzPhp\Push\WebPush\WebPushEncryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class WebPushEncryptorTest
 *
 * @package Tests\Push\WebPush
 */
#[CoversClass(WebPushEncryptor::class)]
final class WebPushEncryptorTest extends TestCase
{
    // RFC 8291 Appendix A example values.
    private const string PLAINTEXT = 'When I grow up, I want to be a watermelon';
    private const string AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const string AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    private const string UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const string AUTH_SECRET = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const string SALT = 'DGv6ra1nlYgDCS1FRnbzlw';
    private const string EXPECTED_BODY = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }

    public function testMatchesTheRfc8291AppendixAVector(): void
    {
        $encryptor = new WebPushEncryptor();

        $body = $encryptor->encrypt(
            self::PLAINTEXT,
            self::unb64(self::UA_PUBLIC),
            self::unb64(self::AUTH_SECRET),
            self::unb64(self::AS_PRIVATE),
            self::unb64(self::SALT),
        );

        $this->assertSame(self::EXPECTED_BODY, self::b64($body));
        $this->assertSame(self::AS_PUBLIC, self::b64(substr($body, 21, 65)));
    }

    public function testRandomEncryptionUsesFreshKeyAndSaltEachTime(): void
    {
        $encryptor = new WebPushEncryptor();
        $ua = self::unb64(self::UA_PUBLIC);
        $auth = self::unb64(self::AUTH_SECRET);

        $a = $encryptor->encrypt('hello', $ua, $auth);
        $b = $encryptor->encrypt('hello', $ua, $auth);

        $this->assertNotSame($a, $b);
        $this->assertSame(16 + 4 + 1 + 65 + strlen('hello') + 1 + 16, strlen($a));
        $this->assertSame("\x00\x00\x10\x00", substr($a, 16, 4));
    }

    public function testHeaderDeclaresRecordSizeAndSenderKey(): void
    {
        $body = (new WebPushEncryptor())->encrypt('x', self::unb64(self::UA_PUBLIC), self::unb64(self::AUTH_SECRET));

        $this->assertSame("\x00\x00\x10\x00", substr($body, 16, 4));
        $this->assertSame(65, ord($body[20]));
        $this->assertSame("\x04", $body[21]);
    }

    public function testRejectsAMalformedUserAgentPublicKey(): void
    {
        $this->expectException(PushException::class);

        (new WebPushEncryptor())->encrypt('x', 'short', self::unb64(self::AUTH_SECRET));
    }

    public function testRejectsAWrongLengthAuthSecret(): void
    {
        $this->expectException(PushException::class);

        (new WebPushEncryptor())->encrypt('x', self::unb64(self::UA_PUBLIC), 'short');
    }

    public function testRejectsAPayloadThatDoesNotFitOneRecord(): void
    {
        $this->expectException(PushException::class);

        (new WebPushEncryptor())->encrypt(str_repeat('a', 4096), self::unb64(self::UA_PUBLIC), self::unb64(self::AUTH_SECRET));
    }
}

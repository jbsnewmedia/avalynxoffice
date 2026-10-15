<?php

declare(strict_types=1);

namespace App\Tests\Licensing;

use App\Licensing\Security\LicenseCodec;
use App\Licensing\Validation\LicenseKeyException;
use PHPUnit\Framework\TestCase;

final class LicenseCodecTest extends TestCase
{
    private LicenseCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new LicenseCodec();
    }

    public function testEncodeAndDecodeRoundTrip(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $payload = ['version' => 1, 'license_id' => 'LIC-1'];

        $key = $this->codec->encode($payload, $secret);
        $decoded = $this->codec->decode($key);

        self::assertSame('WOL1', explode('.', $key)[0]);
        self::assertSame(3, count(explode('.', $key)));
        self::assertSame($payload, $this->codec->decodePayload($decoded['payload_raw']));
        self::assertSame('WOL1.'.explode('.', $key)[1], $decoded['signed_content']);
        self::assertNotSame($decoded['signature'], '');
    }

    public function testDecodeRejectsEmptyKey(): void
    {
        $this->expectException(LicenseKeyException::class);
        $this->codec->decode('');
    }

    public function testDecodeRejectsOversizedKey(): void
    {
        $this->expectException(LicenseKeyException::class);
        $this->codec->decode('WOL1.'.str_repeat('a', LicenseCodec::MAX_KEY_LENGTH).'.sig');
    }

    public function testDecodeRejectsWrongPrefix(): void
    {
        $this->expectException(LicenseKeyException::class);
        $this->codec->decode('XXXX.payload.signature');
    }

    public function testDecodeRejectsWrongPartCount(): void
    {
        $this->expectException(LicenseKeyException::class);
        $this->codec->decode('WOL1.payload');
    }

    public function testDecodeRejectsEmptyPayloadOrSignaturePart(): void
    {
        $this->expectException(LicenseKeyException::class);
        $this->codec->decode('WOL1..signature');
    }

    public function testDecodeRejectsInvalidBase64Url(): void
    {
        $this->expectException(LicenseKeyException::class);
        $this->codec->decode('WOL1.'."!!!invalid!!!.'.'.AAAA");
    }

    public function testDecodeRejectsOversizedPayload(): void
    {
        $this->expectException(LicenseKeyException::class);
        $payload = str_repeat('A', (int) (LicenseCodec::MAX_PAYLOAD_LENGTH * 4 / 3) + 10);
        $this->codec->decode('WOL1.'.$payload.'.c2ln');
    }

    public function testDecodePayloadRejectsInvalidJson(): void
    {
        $this->expectException(LicenseKeyException::class);
        $this->codec->decodePayload('{invalid');
    }

    public function testDecodePayloadRejectsNonObjectJson(): void
    {
        $this->expectException(LicenseKeyException::class);
        $this->codec->decodePayload('[1,2,3]');
    }

    public function testEncodeRejectsEmptySecretKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->codec->encode(['version' => 1], '');
    }
}

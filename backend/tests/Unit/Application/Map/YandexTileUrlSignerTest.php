<?php

namespace App\Tests\Unit\Application\Map;

use App\Application\Map\YandexTileUrlSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class YandexTileUrlSignerTest extends TestCase
{
    public function testItBuildsAWithTtlSignatureWithStableParameterOrder(): void
    {
        $signer = new YandexTileUrlSigner(
            'test-api-key',
            rtrim(strtr(base64_encode('test-signing-secret'), '+/', '-_'), '='),
            new MockClock('2026-08-10T12:34:56+00:00'),
        );

        $url = $signer->url(7, 73, 42);

        self::assertSame(
            'https://tiles.api-maps.yandex.ru/v1/tiles/?apikey=test-api-key&lang=ru_RU&x=73&y=42&z=7&l=map&scale=1&projection=web_mercator&maptype=map&signature=5htCDQpVB_8zVh1Z-_2jgiF7CM8zXmv-UKbPSRXlwnA=',
            $url,
        );
        self::assertStringEndsWith(
            'signature=5htCDQpVB_8zVh1Z-_2jgiF7CM8zXmv-UKbPSRXlwnA=',
            $url,
        );
        self::assertStringNotContainsString('test-signing-secret', $url);
    }

    public function testItRejectsAnInvalidSigningSecret(): void
    {
        $signer = new YandexTileUrlSigner(
            'test-api-key',
            '*not-base64url*',
            new MockClock('2026-08-10T12:34:56+00:00'),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not valid Base64URL');

        $signer->url(0, 0, 0);
    }
}

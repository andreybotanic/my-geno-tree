<?php

namespace App\Application\Map;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class YandexTileUrlSigner
{
    private const string BASE_URL = 'https://tiles.api-maps.yandex.ru';
    private const string TILE_PATH = '/v1/tiles/';

    public function __construct(
        #[Autowire(env: 'YANDEX_TILES_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'YANDEX_TILES_SIGNING_SECRET')]
        private string $signingSecret,
        private ClockInterface $clock,
    ) {
    }

    public function url(int $z, int $x, int $y): string
    {
        $query = http_build_query([
            'apikey'     => $this->apiKey,
            'lang'       => 'ru_RU',
            'x'          => $x,
            'y'          => $y,
            'z'          => $z,
            'l'          => 'map',
            'scale'      => 1,
            'projection' => 'web_mercator',
            'maptype'    => 'map',
        ], encoding_type: PHP_QUERY_RFC3986);
        $urlToSign = self::TILE_PATH . '?' . $query;
        $timestamp = $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:00:00\Z');
        $temporalSecret = hash_hmac(
            'sha256',
            $timestamp,
            $this->decodedSecret(),
            true,
        );
        $signature = strtr(base64_encode(hash_hmac(
            'sha256',
            $urlToSign,
            $temporalSecret,
            true,
        )), '+/', '-_');

        return self::BASE_URL . $urlToSign . '&signature=' . $signature;
    }

    private function decodedSecret(): string
    {
        $encoded = strtr($this->signingSecret, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $secret = base64_decode($encoded, true);
        if (false === $secret || '' === $secret) {
            throw new \LogicException('The Yandex Tiles signing secret is not valid Base64URL data.');
        }

        return $secret;
    }
}

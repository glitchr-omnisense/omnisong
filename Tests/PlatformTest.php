<?php

namespace Omnisong\Tests;

use Omnisong\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    public function testTheLabelThenTheShopsThenTheStreamers(): void
    {
        $platforms = Platform::cases();
        usort($platforms, static fn (Platform $a, Platform $b) => $a->rank() <=> $b->rank());

        self::assertSame([Platform::LABEL, Platform::SHOP, Platform::BANDCAMP, Platform::SPOTIFY, Platform::APPLE_MUSIC], \array_slice($platforms, 0, 5));
        self::assertSame(Platform::OTHER, end($platforms));
        self::assertLessThan(Platform::ITUNES->rank(), Platform::QOBUZ->rank(), 'listening before buying a download');
        self::assertCount(\count($platforms), array_unique(array_map(static fn (Platform $p) => $p->rank(), $platforms)), 'no two at the same rank');
    }

    public function testEachHasANameAndSomeAPlayer(): void
    {
        self::assertSame('Apple Music', Platform::APPLE_MUSIC->label());
        self::assertSame('iTunes Store', Platform::ITUNES->label());
        self::assertTrue(Platform::SPOTIFY->embeddable());
        self::assertTrue(Platform::SOUNDCLOUD->embeddable());
        self::assertFalse(Platform::QOBUZ->embeddable());
        self::assertFalse(Platform::LABEL->embeddable());
    }

    public function testAUrlNamesItsPlatform(): void
    {
        $urls = [
            'https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy' => Platform::SPOTIFY,
            'https://music.apple.com/de/album/beethoven-symphonies-nos-5-7/1644892939' => Platform::APPLE_MUSIC,
            'https://geo.music.apple.com/de/album/_/1644892939?mt=1&app=music' => Platform::APPLE_MUSIC,
            'https://www.apple.com/de/iphone/' => null,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => Platform::YOUTUBE,
            'https://youtu.be/dQw4w9WgXcQ' => Platform::YOUTUBE,
            'https://music.youtube.com/playlist?list=OLAK5uy_abc' => Platform::YOUTUBE_MUSIC,
            'https://www.deezer.com/de/album/123456' => Platform::DEEZER,
            'https://open.qobuz.com/album/abc' => Platform::QOBUZ,
            'https://app.idagio.com/albums/abc' => Platform::IDAGIO,
            'https://listen.tidal.com/album/100000001' => Platform::TIDAL,
            'https://music.amazon.com/albums/B000000001' => Platform::AMAZON_MUSIC,
            'https://amazon.com/dp/B000000001' => Platform::AMAZON,
            'https://www.prestomusic.com/classical/products/1' => Platform::PRESTO,
            'https://artist.bandcamp.com/album/x' => Platform::BANDCAMP,
            'https://soundcloud.com/artist/track' => Platform::SOUNDCLOUD,
            'https://label.example.org/' => null,
            'not a url' => null,
        ];
        foreach ($urls as $url => $platform) {
            self::assertSame($platform, Platform::fromUrl($url), $url);
        }
    }

    public function testOdeslisKeysAreOurs(): void
    {
        self::assertSame(Platform::APPLE_MUSIC, Platform::fromOdesli('appleMusic'));
        self::assertSame(Platform::YOUTUBE_MUSIC, Platform::fromOdesli('youtubeMusic'));
        self::assertSame(Platform::AMAZON, Platform::fromOdesli('amazonStore'));
        self::assertSame(Platform::AMAZON_MUSIC, Platform::fromOdesli('amazonMusic'));
        self::assertNull(Platform::fromOdesli('yandex'));
        foreach (['spotify', 'itunes', 'youtube', 'deezer', 'tidal', 'soundcloud', 'napster', 'pandora', 'anghami', 'boomplay', 'audiomack', 'audius'] as $key) {
            self::assertSame($key, Platform::fromOdesli($key)?->value);
        }
    }
}

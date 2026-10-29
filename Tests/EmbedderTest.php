<?php

namespace Omnisong\Tests;

use Omnisong\Model\PlatformLink;
use Omnisong\Platform;
use Omnisong\Player\Embedder;
use Omnisong\Player\EmbedOptions;
use PHPUnit\Framework\TestCase;

final class EmbedderTest extends TestCase
{
    private static function src(Platform $platform, string $url, ?EmbedOptions $options = null): ?string
    {
        return (new Embedder())->embed(new PlatformLink($platform, $url), $options)?->src;
    }

    public function testSpotify(): void
    {
        self::assertSame('https://open.spotify.com/embed/album/4aawyAB9vmqN3uQ7FjRGTy?utm_source=omnisong&theme=1', self::src(Platform::SPOTIFY, 'https://open.spotify.com/intl-de/album/4aawyAB9vmqN3uQ7FjRGTy?si=x'));
        $track = (new Embedder())->embed(new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/track/3n3Ppam7vgaVa1iaRUc9Lp'));
        self::assertSame('https://open.spotify.com/embed/track/3n3Ppam7vgaVa1iaRUc9Lp?utm_source=omnisong&theme=1', $track->src);
        self::assertSame(152, $track->height, 'a track: the compact player');
        self::assertSame(352, (new Embedder())->embed(new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy'))->height);
        $artist = (new Embedder())->embed(new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/artist/0OdUWJ0sBjDrqHygGUXeCF?si=x'));
        self::assertSame('https://open.spotify.com/embed/artist/0OdUWJ0sBjDrqHygGUXeCF?utm_source=omnisong&theme=1', $artist->src, 'the artist\'s profile: their top tracks');
        self::assertSame(352, $artist->height);
        $playlist = (new Embedder())->embed(new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M'));
        self::assertSame('https://open.spotify.com/embed/playlist/37i9dQZF1DXcBWIGoYBM5M?utm_source=omnisong&theme=1', $playlist->src);
        self::assertSame(352, $playlist->height);
        self::assertNull(self::src(Platform::SPOTIFY, 'https://open.spotify.com/search/tourret'), 'a search has no player');
    }

    public function testAppleMusic(): void
    {
        $album = (new Embedder())->embed(new PlatformLink(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/perspectives-concertantes/1793146044'));
        self::assertSame('https://embed.music.apple.com/de/album/perspectives-concertantes/1793146044', $album->src);
        self::assertSame(450, $album->height);

        $track = (new Embedder())->embed(new PlatformLink(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/concerto/1793146044?i=1793146055&uo=4'));
        self::assertSame('https://embed.music.apple.com/de/album/concerto/1793146044?i=1793146055', $track->src, 'one track of the album: ?i= kept, the rest dropped');
        self::assertSame(175, $track->height);

        $playlist = (new Embedder())->embed(new PlatformLink(Platform::APPLE_MUSIC, 'https://music.apple.com/de/playlist/harp-essentials/pl.1b2d8a3c4e5f'));
        self::assertSame('https://embed.music.apple.com/de/playlist/harp-essentials/pl.1b2d8a3c4e5f', $playlist->src);
        self::assertSame(450, $playlist->height);
        self::assertSame('https://embed.music.apple.com/fr/album/x/1793146044', self::src(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/x/1793146044', new EmbedOptions(country: 'FR')), 'the store chosen');
    }

    public function testDeezer(): void
    {
        self::assertSame('https://widget.deezer.com/widget/light/album/302127', self::src(Platform::DEEZER, 'https://www.deezer.com/de/album/302127'));
        $track = (new Embedder())->embed(new PlatformLink(Platform::DEEZER, 'https://www.deezer.com/track/3135556'));
        self::assertSame('https://widget.deezer.com/widget/light/track/3135556', $track->src);
        self::assertSame(90, $track->height);
        self::assertSame('https://widget.deezer.com/widget/light/artist/5603906', self::src(Platform::DEEZER, 'https://www.deezer.com/fr/artist/5603906'));
        self::assertSame('https://widget.deezer.com/widget/light/playlist/1479458365', self::src(Platform::DEEZER, 'https://www.deezer.com/playlist/1479458365'));
    }

    public function testYoutube(): void
    {
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&modestbranding=1', self::src(Platform::YOUTUBE, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42'));
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&modestbranding=1', self::src(Platform::YOUTUBE, 'https://youtu.be/dQw4w9WgXcQ'));
        self::assertSame('https://www.youtube-nocookie.com/embed/videoseries?list=OLAK5uy_kXr0j&rel=0&modestbranding=1', self::src(Platform::YOUTUBE_MUSIC, 'https://music.youtube.com/playlist?list=OLAK5uy_kXr0j'));
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?list=PL123&autoplay=1&rel=0&modestbranding=1', self::src(Platform::YOUTUBE, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123', new EmbedOptions(autoplay: true)));
        self::assertNull(self::src(Platform::YOUTUBE, 'https://www.youtube.com/@channel'));
    }

    public function testSoundcloud(): void
    {
        $src = self::src(Platform::SOUNDCLOUD, 'https://soundcloud.com/artist/track', new EmbedOptions(color: 'ff5500'));
        self::assertStringStartsWith('https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fartist%2Ftrack&auto_play=false', $src);
        self::assertStringContainsString('color=%23ff5500', $src, 'the colour encoded once: SoundCloud reads #ff5500');
        self::assertStringNotContainsString('%2523', $src);
    }

    public function testWhatHasNoPlayerHasNoEmbed(): void
    {
        self::assertNull(self::src(Platform::QOBUZ, 'https://open.qobuz.com/album/abc'));
        self::assertNull(self::src(Platform::LABEL, 'https://www.es-dur.de'));
        self::assertNull(self::src(Platform::DEEZER, 'https://www.deezer.com/de/search/tourret'));
        self::assertFalse((new Embedder())->supports(Platform::TIDAL));
        self::assertTrue((new Embedder())->supports(Platform::APPLE_MUSIC));
    }

    public function testTheDarkTheme(): void
    {
        $dark = new EmbedOptions(EmbedOptions::DARK);

        self::assertStringEndsWith('theme=0', self::src(Platform::SPOTIFY, 'https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy', $dark));
        self::assertStringEndsWith('?theme=dark', self::src(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/x/1793146044', $dark));
        self::assertStringEndsWith('?i=2&theme=dark', self::src(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/x/1793146044?i=2', $dark));
        self::assertSame('https://widget.deezer.com/widget/dark/album/302127', self::src(Platform::DEEZER, 'https://www.deezer.com/album/302127', $dark));
        self::assertStringEndsWith('theme=0', (new Embedder($dark))->embed(new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/album/a1'))->src, 'the site\'s default');
    }

    public function testTheIframeIsLazySandboxedAndEscaped(): void
    {
        $embed = (new Embedder())->embed(new PlatformLink(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/x/1793146044?i=2', null, 'Tourret "live"'));

        self::assertSame(Platform::APPLE_MUSIC, $embed->platform);
        self::assertStringStartsWith('<iframe src="https://embed.music.apple.com/de/album/x/1793146044?i=2"', $embed->html);
        self::assertStringContainsString('loading="lazy"', $embed->html);
        self::assertStringContainsString('sandbox="', $embed->html);
        self::assertStringContainsString('title="Tourret &quot;live&quot;"', $embed->html);
        self::assertStringContainsString('height="175"', $embed->html);
    }
}

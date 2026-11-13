<?php

namespace Omnisong;

/**
 * Where a recording can be listened to or bought. The label comes first:
 * a site shows "released by Label Exemple, buy it there" before "listen on
 * Spotify". rank() orders a PlatformLinks; fromOdesli() maps song.link's
 * keys; embeddable() says whether Player\Embedder knows an iframe for it.
 */
enum Platform: string
{
    case LABEL = 'label';
    case SHOP = 'shop';
    case BANDCAMP = 'bandcamp';
    case SPOTIFY = 'spotify';
    case APPLE_MUSIC = 'apple_music';
    case ITUNES = 'itunes';
    case YOUTUBE = 'youtube';
    case YOUTUBE_MUSIC = 'youtube_music';
    case DEEZER = 'deezer';
    case QOBUZ = 'qobuz';
    case IDAGIO = 'idagio';
    case TIDAL = 'tidal';
    case AMAZON_MUSIC = 'amazon_music';
    case AMAZON = 'amazon';
    case PRESTO = 'presto';
    case HIGHRESAUDIO = 'highresaudio';
    case SOUNDCLOUD = 'soundcloud';
    case NAPSTER = 'napster';
    case PANDORA = 'pandora';
    case ANGHAMI = 'anghami';
    case BOOMPLAY = 'boomplay';
    case AUDIOMACK = 'audiomack';
    case AUDIUS = 'audius';
    case OTHER = 'other';

    /** Lower first. The label, then the shops, then the streamers by weight in classical music. */
    public function rank(): int
    {
        return match ($this) {
            self::LABEL => 0, self::SHOP => 1, self::BANDCAMP => 2,
            self::SPOTIFY => 10, self::APPLE_MUSIC => 11, self::YOUTUBE_MUSIC => 12, self::YOUTUBE => 13, self::DEEZER => 14,
            self::QOBUZ => 15, self::IDAGIO => 16, self::TIDAL => 17, self::AMAZON_MUSIC => 18,
            self::ITUNES => 20, self::AMAZON => 21, self::PRESTO => 22, self::HIGHRESAUDIO => 23,
            self::SOUNDCLOUD => 30, self::NAPSTER => 31, self::PANDORA => 32, self::ANGHAMI => 33, self::BOOMPLAY => 34, self::AUDIOMACK => 35, self::AUDIUS => 36,
            self::OTHER => 99,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::LABEL => 'Label', self::SHOP => 'Shop', self::BANDCAMP => 'Bandcamp',
            self::SPOTIFY => 'Spotify', self::APPLE_MUSIC => 'Apple Music', self::ITUNES => 'iTunes Store',
            self::YOUTUBE => 'YouTube', self::YOUTUBE_MUSIC => 'YouTube Music', self::DEEZER => 'Deezer',
            self::QOBUZ => 'Qobuz', self::IDAGIO => 'Idagio', self::TIDAL => 'Tidal', self::AMAZON_MUSIC => 'Amazon Music', self::AMAZON => 'Amazon',
            self::PRESTO => 'Presto Music', self::HIGHRESAUDIO => 'HIGHRESAUDIO', self::SOUNDCLOUD => 'SoundCloud',
            self::NAPSTER => 'Napster', self::PANDORA => 'Pandora', self::ANGHAMI => 'Anghami', self::BOOMPLAY => 'Boomplay',
            self::AUDIOMACK => 'Audiomack', self::AUDIUS => 'Audius', self::OTHER => 'Other',
        };
    }

    /** Whether the core's Embedder knows an iframe for it (no key needed). */
    public function embeddable(): bool
    {
        return \in_array($this, [self::SPOTIFY, self::APPLE_MUSIC, self::DEEZER, self::YOUTUBE, self::YOUTUBE_MUSIC, self::SOUNDCLOUD], true);
    }

    /** song.link's platform keys (linksByPlatform) to ours; null for one we do not list. */
    public static function fromOdesli(string $key): ?self
    {
        return match ($key) {
            'spotify' => self::SPOTIFY, 'appleMusic' => self::APPLE_MUSIC, 'itunes' => self::ITUNES,
            'youtube' => self::YOUTUBE, 'youtubeMusic' => self::YOUTUBE_MUSIC, 'deezer' => self::DEEZER,
            'tidal' => self::TIDAL, 'amazonMusic' => self::AMAZON_MUSIC, 'amazonStore' => self::AMAZON,
            'soundcloud' => self::SOUNDCLOUD, 'napster' => self::NAPSTER, 'pandora' => self::PANDORA,
            'anghami' => self::ANGHAMI, 'boomplay' => self::BOOMPLAY, 'audiomack' => self::AUDIOMACK, 'audius' => self::AUDIUS,
            'bandcamp' => self::BANDCAMP,
            default => null,
        };
    }

    /** The platform a URL belongs to, from its host; null when unknown. */
    public static function fromUrl(string $url): ?self
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $host = preg_replace('/^(www|open|music|listen|play|geo)\./', '', $host) ?? $host;

        return match (true) {
            str_ends_with($host, 'spotify.com') => self::SPOTIFY,
            str_ends_with($host, 'apple.com') => preg_match('#/(album|song|playlist|artist|music-video)/#', $url) ? self::APPLE_MUSIC : null,
            str_ends_with($host, 'youtube.com') || 'youtu.be' === $host => str_starts_with((string) parse_url($url, \PHP_URL_HOST), 'music.') ? self::YOUTUBE_MUSIC : self::YOUTUBE,
            str_ends_with($host, 'deezer.com') => self::DEEZER,
            str_ends_with($host, 'qobuz.com') => self::QOBUZ,
            str_ends_with($host, 'idagio.com') => self::IDAGIO,
            str_ends_with($host, 'tidal.com') => self::TIDAL,
            str_ends_with($host, 'amazon.com') || str_ends_with($host, 'amazon.fr') || str_ends_with($host, 'amazon.de') || str_ends_with($host, 'amazon.co.uk') => str_contains($url, '/music/') || str_contains($url, '/albums/') ? self::AMAZON_MUSIC : self::AMAZON,
            str_ends_with($host, 'prestomusic.com') => self::PRESTO,
            str_ends_with($host, 'highresaudio.com') => self::HIGHRESAUDIO,
            str_ends_with($host, 'bandcamp.com') => self::BANDCAMP,
            str_ends_with($host, 'soundcloud.com') => self::SOUNDCLOUD,
            str_ends_with($host, 'napster.com') => self::NAPSTER,
            str_ends_with($host, 'pandora.com') => self::PANDORA,
            default => null,
        };
    }
}

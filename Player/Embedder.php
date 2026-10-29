<?php

namespace Omnisong\Player;

use Omnisong\Model\Embed;
use Omnisong\Model\PlatformLink;
use Omnisong\Platform;

/**
 * The platforms' own players, from their public embed URLs - no key, no
 * SDK: Spotify (open.spotify.com/embed: albums, tracks, playlists, artists), Apple Music (embed.music.apple.com),
 * Deezer (widget.deezer.com), YouTube (youtube-nocookie.com), SoundCloud
 * (w.soundcloud.com). Every iframe is lazy and sandboxed as the platform
 * requires; a site loads it on a click, never with the page.
 */
final class Embedder implements EmbedderInterface
{
    /** @param EmbedOptions|null $defaults how an embed looks when embed() is not told (the site's theme) */
    public function __construct(private readonly ?EmbedOptions $defaults = null)
    {
    }

    public function supports(Platform $platform): bool
    {
        return $platform->embeddable();
    }

    public function embed(PlatformLink $link, ?EmbedOptions $options = null): ?Embed
    {
        $options ??= $this->defaults ?? new EmbedOptions();
        [$src, $height] = match ($link->platform) {
            Platform::SPOTIFY => $this->spotify($link->url, $options),
            Platform::APPLE_MUSIC => $this->appleMusic($link->url, $options),
            Platform::DEEZER => $this->deezer($link->url, $options),
            Platform::YOUTUBE, Platform::YOUTUBE_MUSIC => $this->youtube($link->url, $options),
            Platform::SOUNDCLOUD => $this->soundcloud($link->url, $options),
            default => [null, 0],
        };
        if (null === $src) {
            return null;
        }
        $title = htmlspecialchars($link->title(), \ENT_QUOTES);
        $html = \sprintf(
            '<iframe src="%s" title="%s" width="100%%" height="%d" style="border:0;border-radius:12px" frameborder="0" loading="lazy" allowfullscreen allow="autoplay *; encrypted-media *; fullscreen *; clipboard-write" sandbox="allow-forms allow-popups allow-same-origin allow-scripts allow-storage-access-by-user-activation allow-top-navigation-by-user-activation"></iframe>',
            htmlspecialchars($src, \ENT_QUOTES),
            $title,
            $height,
        );

        return new Embed($link->platform, $html, $src, 100, $height);
    }

    /** @return array{0: ?string, 1: int} */
    private function spotify(string $url, EmbedOptions $o): array
    {
        if (!preg_match('#open\.spotify\.com/(?:intl-[a-z]{2}/)?(album|track|playlist|artist|episode|show)/([A-Za-z0-9]+)#', $url, $m)) {
            return [null, 0];
        }
        $height = 'track' === $m[1] || $o->compact ? 152 : 352;
        $query = ['utm_source' => 'omnisong', 'theme' => $o->dark() ? '0' : '1'];

        return [\sprintf('https://open.spotify.com/embed/%s/%s?%s', $m[1], $m[2], http_build_query($query)), $height];
    }

    /** @return array{0: ?string, 1: int} */
    private function appleMusic(string $url, EmbedOptions $o): array
    {
        if (!preg_match('#music\.apple\.com/([a-z]{2})/(album|playlist|song)/([^?\#]+)(\?[^\#]*)?#', $url, $m)) {
            return [null, 0];
        }
        parse_str(ltrim($m[4] ?? '', '?'), $query);
        $country = $o->country ? strtolower($o->country) : $m[1];
        $single = 'song' === $m[2] || isset($query['i']) || $o->compact;
        $src = \sprintf('https://embed.music.apple.com/%s/%s/%s', $country, $m[2], $m[3]);
        $params = array_filter(['i' => $query['i'] ?? null, 'theme' => $o->dark() ? 'dark' : null]);
        if ($params) {
            $src .= '?'.http_build_query($params);
        }

        return [$src, $single ? 175 : 450];
    }

    /** @return array{0: ?string, 1: int} */
    private function deezer(string $url, EmbedOptions $o): array
    {
        if (!preg_match('#deezer\.com/(?:[a-z]{2}/)?(album|track|playlist|artist)/(\d+)#', $url, $m)) {
            return [null, 0];
        }
        $single = 'track' === $m[1] || $o->compact;

        return [\sprintf('https://widget.deezer.com/widget/%s/%s/%s', $o->dark() ? 'dark' : 'light', $m[1], $m[2]), $single ? 90 : 300];
    }

    /** @return array{0: ?string, 1: int} */
    private function youtube(string $url, EmbedOptions $o): array
    {
        $id = null;
        $list = null;
        if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{11})#', $url, $m)) {
            $id = $m[1];
        }
        if (preg_match('#[?&]list=([A-Za-z0-9_-]+)#', $url, $m)) {
            $list = $m[1];
        }
        if (null === $id && null === $list) {
            return [null, 0];
        }
        $query = array_filter(['list' => $list, 'autoplay' => $o->autoplay ? 1 : null]) + ['rel' => 0, 'modestbranding' => 1];
        $src = null !== $id
            ? \sprintf('https://www.youtube-nocookie.com/embed/%s?%s', $id, http_build_query($query))
            : \sprintf('https://www.youtube-nocookie.com/embed/videoseries?%s', http_build_query($query));

        return [$src, $o->compact ? 200 : 315];
    }

    /** @return array{0: ?string, 1: int} */
    private function soundcloud(string $url, EmbedOptions $o): array
    {
        if (!str_contains($url, 'soundcloud.com/')) {
            return [null, 0];
        }
        $query = ['url' => $url, 'auto_play' => $o->autoplay ? 'true' : 'false', 'visual' => 'false', 'show_comments' => 'false', 'hide_related' => 'true'];
        if ($o->color) {
            $query['color'] = '#'.ltrim($o->color, '#'); // http_build_query() encodes it: %23
        }

        return ['https://w.soundcloud.com/player/?'.http_build_query($query), $o->compact ? 20 : 166];
    }
}

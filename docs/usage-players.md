---
title: Players
order: 32
---

# Players

`Omnisong\Player\Embedder` turns a `PlatformLink` into the platform's own player, an iframe built
from the platform's public embed URL: no key, no SDK, no call.

```php
use Omnisong\Model\PlatformLink;
use Omnisong\Platform;
use Omnisong\Player\Embedder;
use Omnisong\Player\EmbedOptions;

$embedder = new Embedder(new EmbedOptions(EmbedOptions::DARK));   // the site's defaults

$embed = $embedder->embed($release->links->get(Platform::SPOTIFY));          // ?Embed
$embed = $embedder->embed(new PlatformLink(Platform::DEEZER, 'https://www.deezer.com/fr/album/302127'),
    new EmbedOptions(compact: true));                                         // these options instead of the defaults

echo $embed?->html;      // the iframe
$embed->src;             // https://widget.deezer.com/...: what frame-src must allow
$embed->height;          // pixels; the width is always 100 %

foreach ($release->links->embeddable() as $link) {     // those a player exists for
    // ...
}
```

The options given to `embed()` replace the defaults given to the constructor as a whole: they are
not merged key by key (the Twig function does merge them, [Symfony](symfony.md#twig)). With neither,
`new EmbedOptions()`: light, full size, no autoplay.

`embed()` returns `null` when the platform has no player (`supports()` is false: only Spotify,
Apple Music, Deezer, YouTube, YouTube Music and SoundCloud have one) or when the URL is not one the
platform embeds (a Spotify search page, an Apple Music artist page).

## The iframe

Every player is the same iframe, its `src` and `height` the platform's:

```html
<iframe src="https://embed.music.apple.com/de/album/perspectives-concertantes/1793146044" title="Apple Music" width="100%" height="450" style="border:0;border-radius:12px" frameborder="0" loading="lazy" allowfullscreen allow="autoplay *; encrypted-media *; fullscreen *; clipboard-write" sandbox="allow-forms allow-popups allow-same-origin allow-scripts allow-storage-access-by-user-activation allow-top-navigation-by-user-activation"></iframe>
```

- `loading="lazy"` and a sandbox that lets the player run, open its own pages on a click, and use
  its storage after the listener's gesture;
- the `title` is the link's `title()` (the platform's name), and both it and `src` are
  HTML-escaped;
- no `width` choice: the iframe fills its container, and `Embed::$width` is `100`.

Load the player on a click, not with the page: it sets the platform's cookies, which is a matter of
the site's consent policy. Spotify, Apple Music and Deezer play the whole track to a listener signed
in with a subscription, and a 30-second preview to everyone else.

## Per platform

The URLs each player reads and what it builds (checked on 2026-10-26 by running the embedder on
each URL below):

| Platform | Reads | `src` | Height (full / compact) | Options read |
|---|---|---|---|---|
| Spotify | `open.spotify.com/[intl-xx/]{album,track,playlist,artist,episode,show}/<id>` | `https://open.spotify.com/embed/<kind>/<id>?utm_source=omnisong&theme=1` (`theme=0` dark) | 352 / 152; a track is always 152 | `theme`, `compact` |
| Apple Music | `music.apple.com/<cc>/{album,playlist,song}/<slug>/<id>[?i=<track>]` (also `geo.music.apple.com`) | `https://embed.music.apple.com/<cc>/<kind>/<slug>/<id>`, with `i` kept and `theme=dark` when dark | 450 / 175; a song or `?i=` is always 175 | `theme`, `compact`, `country` (replaces `<cc>`, lower case) |
| Deezer | `deezer.com/[xx/]{album,track,playlist,artist}/<digits>` | `https://widget.deezer.com/widget/{light,dark}/<kind>/<id>` | 300 / 90; a track is always 90 | `theme`, `compact` |
| YouTube, YouTube Music | `youtu.be/<id>`, `youtube.com/watch?v=<id>`, `/embed/<id>`, `/shorts/<id>`, `/live/<id>`, any URL with `list=` | `https://www.youtube-nocookie.com/embed/<id>?[list=..&][autoplay=1&]rel=0&modestbranding=1`, or `/embed/videoseries?list=...` for a playlist alone | 315 / 200 | `compact`, `autoplay` (no theme) |
| SoundCloud | any URL containing `soundcloud.com/` | `https://w.soundcloud.com/player/?url=<the URL>&auto_play=false&visual=false&show_comments=false&hide_related=true[&color=...]` | 166 / 20 | `compact`, `autoplay`, `color` (no theme) |

Examples, as the embedder answered them:

```
https://open.spotify.com/intl-de/album/4aawyAB9vmqN3uQ7FjRGTy
  src=https://open.spotify.com/embed/album/4aawyAB9vmqN3uQ7FjRGTy?utm_source=omnisong&theme=1 height=352
https://music.apple.com/de/album/perspectives-concertantes/1793146044?i=1793146045
  src=https://embed.music.apple.com/de/album/perspectives-concertantes/1793146044?i=1793146045 height=175
https://www.deezer.com/fr/album/302127
  src=https://widget.deezer.com/widget/light/album/302127 height=300
https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123
  src=https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?list=PL123&rel=0&modestbranding=1 height=315
https://music.youtube.com/playlist?list=OLAK5uy_abc
  src=https://www.youtube-nocookie.com/embed/videoseries?list=OLAK5uy_abc&rel=0&modestbranding=1 height=315
https://soundcloud.com/artist/track
  src=https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fartist%2Ftrack&auto_play=false&visual=false&show_comments=false&hide_related=true height=166
https://open.spotify.com/search/foo
  no player (null)
```

Spotify's player is built for an episode or a show too, although `Platform::fromUrl()` names any
`spotify.com` URL Spotify. YouTube has no theme: the dark option changes nothing there.

## Content-Security-Policy

`Embed::$src` names the host a page's `frame-src` must allow. The hosts, by platform:

| Platform | `frame-src` |
|---|---|
| Spotify | `https://open.spotify.com` |
| Apple Music | `https://embed.music.apple.com` |
| Deezer | `https://widget.deezer.com` |
| YouTube, YouTube Music | `https://www.youtube-nocookie.com` |
| SoundCloud | `https://w.soundcloud.com` |

The players load further resources of their own (scripts, audio); a strict policy on the page does
not reach inside the iframe.

## Previews without a player

A track's `previewUrl` (iTunes: 30 seconds of AAC in an `.m4a`) plays in an `<audio>` element with
no third-party iframe and no cookie:

```twig
{% for track in release.tracks %}
    {{ track.position }}. {{ track.title }}
    {% if track.previewUrl %}<audio controls preload="none" src="{{ track.previewUrl }}"></audio>{% endif %}
{% endfor %}
{% set apple = release.links.get(constant('Omnisong\\Platform::APPLE_MUSIC')) %}
{% if apple %}<a href="{{ apple.url }}">Apple Music</a>{% endif %}
```

Apple licenses the previews for promotion next to a link to its store: keep the Apple Music link
beside them (the `omnisong/itunes` README's Responsibility section names the terms).

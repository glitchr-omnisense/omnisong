# omnisense/omnisong

One contract for listening to a musician's catalogue - the Omnibus of records. Listening, not
publishing: where a recording is, what is on it, the previews and the players, read from the
services that know it. And the label first: a musician's site shows "released by ES-DUR, buy it
there" before "listen on Spotify".

Published as `omnisense/omnisong` (formerly `glitchr/omnisong`, which this package replaces: a
`composer.json` that still requires the old name keeps resolving to this package, never both).
`omnisense/` is the vendor of every family's core; the providers keep theirs (`omnisong/itunes`...).

```php
$release = $catalog->release(Reference::upc('4015372820954'), new Label('ES-DUR', 'https://www.es-dur.de'));

$release->tracks[0]->previewUrl;                 // 30 seconds, for an <audio> element
$release->links->first();                        // ES-DUR, then the streamers in order
$embedder->embed($release->links->get(Platform::SPOTIFY));   // the platform's own player
$catalog->releases('Anaëlle Tourret');           // what an artist put out, newest first
```

This package holds the contract (`CatalogInterface`, `CatalogFactory`, `Registry`), the aggregator
(`Catalog\Catalog`), the models (`Reference`, `Release`, `Track`, `Label`, `PlatformLinks`,
`Embed`...), the `Platform` enum, the `Player\Embedder` and a bridge for Symfony. It needs no
framework: it requires nothing but `symfony/http-client-contracts`, each catalogue package
`symfony/http-client`. Each catalogue is a package of its own:

| Package | Catalogue |
|---|---|
| `omnisong/odesli` | Odesli (song.link): the same song or album on every platform, by a URL or a platform's id |
| `omnisong/itunes` | The iTunes Search API: releases, their tracks and 30-second previews, an artist's albums - no key |

A reference is a URL on any platform, a UPC/EAN (a release), an ISRC (a recording) or a
platform's own id; `Reference::parse()` reads any of them from a string. A catalogue answers what
it can and throws `NotSupportedException` for the rest: `Catalog` asks every configured catalogue
in turn and merges what they say. iTunes finds the release by its UPC and gives its tracks; Odesli,
which reads no UPC, is asked again by the iTunes id and gives the other platforms. A catalogue that
is down, rate limited or refused is skipped and named in `$catalog->incomplete`: a site does not
cache that half answer.

`PlatformLinks` are in the order a site shows them (`Platform::rank()`): the label, its shop,
Bandcamp, then Spotify, Apple Music, YouTube Music, YouTube, Deezer, Qobuz, Idagio, Tidal, Amazon
Music, then the stores. `toArray()` / `fromArray()` store them as URLs by platform.

## Plain PHP

```sh
composer require omnisense/omnisong omnisong/itunes
```

```php
require __DIR__.'/vendor/autoload.php';

use Omnisong\Catalog\Catalog;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Model\Label;
use Omnisong\Model\Reference;
use Omnisong\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new ItunesCatalogFactory(HttpClient::create())], [
    'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de']],
]);
$catalog = new Catalog($registry->all());

$release = $catalog->release(Reference::upc('4015372820954'), new Label('ES-DUR', 'https://www.es-dur.de'));
echo $release->title, "\n";                       // Perspectives Concertantes
echo $release->tracks[0]->previewUrl, "\n";       // 30 seconds, for an <audio> element
echo $release->links->first()->url, "\n";         // https://www.es-dur.de: the label first
```

No key: the iTunes Search API is open. A factory takes the HTTP client to call with - the
application's, a `MockHttpClient` in a test - and makes its own when given none. The whole
script and its answer, and the rest: [docs/installation.md](docs/installation.md). No class of a
framework is loaded on the way: `Tests/BareTest.php` checks it in a process of its own, and so
does `docker compose run --rm omnisong bare`.

## The players

`Player\Embedder` turns a link into the platform's own iframe, from its public embed URL - no
key, no SDK: Spotify (albums, tracks, playlists, artists), Apple Music (albums, songs - `?i=` for
one track of an album -, playlists), Deezer (albums, tracks, playlists, artists), YouTube and
YouTube Music (through youtube-nocookie.com) and SoundCloud. Light or dark, compact or full; the
iframe is lazy and sandboxed, and `Embed::$src` names the host a Content-Security-Policy's
`frame-src` must allow.

Spotify, Apple Music and Deezer play the whole track to a listener signed in with a subscription -
a stream counted for the artist, as in their apps - and a 30-second preview to everyone else.
Load the player on a click, not with the page: it sets the platform's cookies.

## Symfony

In a Symfony application a bundle does the wiring; its components (`symfony/config`,
`symfony/dependency-injection`, `symfony/http-kernel`, `twig/twig`) are not required by this
package: the application has them ([docs/symfony.md](docs/symfony.md)).
`Omnisong\Bridge\Symfony\OmnisongBundle`: every `omnisong/*` catalogue installed registered, the
catalogues built from configuration, `CatalogInterface` autowired as the aggregator that asks them
in the configured order, each catalogue injectable by its name, the `Embedder` with the site's
theme.

```yaml
omnisong:
    catalogs:            # by name, in the order the aggregator asks them
        odesli: { factory: odesli, options: { api_key: '%env(default::ODESLI_API_KEY)%', country: 'DE' } }
        itunes: { factory: itunes, options: { country: 'de' } }
    player:
        theme: light     # light|dark, the Embedder's default
        country: ~       # overrides the store Apple Music opens
```

```php
public function __construct(CatalogInterface $catalog, EmbedderInterface $embedder) {}
public function __construct(CatalogInterface $itunes) {}   // one catalogue, by its name
```

With Twig installed, the templates have the players and the platforms' names:

```twig
{{ omnisong_embed(release.links.get(platform)) }}
{{ omnisong_embed('https://open.spotify.com/album/...', {theme: 'dark', compact: true}) }}
{{ omnisong_platform('apple_music').label }}   {{ link.platform.value|omnisong_platform_label }}
```

An application's own `CatalogFactoryInterface` is registered too (autoconfigured).

## Docker: every catalogue, for real

`docker/` runs this package with every `omnisong/*` catalogue installed - from GitHub, or from the
checkouts beside this one when `OMNISONG_PLUGINS=../..` is set - and a console that asks the real
services with the settings in `docker/.env` (copy `.env.dist`). iTunes needs no key; Odesli
refuses keyless calls (401) and is skipped, and said so, until `ODESLI_API_KEY` is set.

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnisong catalogs                 # which catalogues are installed and configured
docker compose run --rm omnisong links 4015372820954 --label ES-DUR --label-url https://www.es-dur.de
docker compose run --rm omnisong release 4015372820954 --label ES-DUR --label-url https://www.es-dur.de   # JSON
docker compose run --rm omnisong releases "Brieuc Vourch" --limit 10
docker compose run --rm omnisong embed https://music.apple.com/de/album/perspectives-concertantes/1793146044 --dark
docker compose run --rm omnisong bare                     # plain PHP: no bundle, no container, and what PHP loaded
docker compose run --rm omnisong test                     # every package's tests
```

## Documentation

- [Installation and first calls](docs/installation.md): plain PHP first
- [Symfony](docs/symfony.md)
- [The Docker harness](docs/harness.md)

## Responsibility

`omnisense/omnisong` and its `omnisong/*` providers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (keys, promotional use and attribution, rate limits), named with a link in the
"Responsibility" section of the README of each provider that reaches one. The MIT licence covers
this code only and grants no right to any service or to its data.

Whoever installs and runs these packages is responsible for complying with those terms, for
obtaining whatever they require, and for any use made of what they return. The authors publish them
free of charge, make no commercial use of them, and accept no liability for how they are used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.

---
title: Installation
order: 10
---

# Installation and first calls

```sh
composer require omnisense/omnisong omnisong/itunes  # no key
composer require omnisong/odesli                      # with a key from developers@song.link
```

## Requirements

- PHP 8.2 or later; no extension beyond those every PHP build has (JSON, PCRE).
- `omnisense/omnisong` requires `symfony/http-client-contracts` (^3.0) and nothing else.
- Each catalogue package (`omnisong/itunes`, `omnisong/odesli`) requires `omnisense/omnisong` and
  `symfony/http-client` (^6.4, ^7.0 or ^8.0), a library that stands alone and loads no framework.
- For the Symfony bundle: `symfony/config`, `symfony/dependency-injection` and
  `symfony/http-kernel` (6.4, 7 or 8), and `twig/twig` (3) for the template functions. They are
  suggested, not required: a Symfony application has them.

Omnisong runs the same in plain PHP, in a worker, in Laravel or Slim, and in Symfony, where a bundle
does the wiring ([Symfony](symfony.md)).

## Plain PHP

```php
<?php // bare.php

require __DIR__.'/vendor/autoload.php';

use Omnisong\Catalog\Catalog;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Model\Label;
use Omnisong\Model\Reference;
use Omnisong\Platform;
use Omnisong\Player\Embedder;
use Omnisong\Registry;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$registry = new Registry([new ItunesCatalogFactory($http)], [
    'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de']],
]);
$catalog = new Catalog($registry->all());   // asks every configured catalogue in turn and merges what they say

// A release by its UPC, the label first
$release = $catalog->release(Reference::upc('4015372820954'), new Label('ES-DUR', 'https://www.es-dur.de'));

echo $release->title, ' (', $release->releasedAt->format('Y'), ', ', $release->label->name, ")\n\n";
foreach ($release->tracks as $track) {
    printf("%d. %s\n", $track->position, $track->title);
}
echo "\n", 'preview: ', $release->tracks[0]->previewUrl, "\n\n";
foreach ($release->links as $link) {
    printf("%-12s %s\n", $link->platform->value, $link->url);
}
echo "\n", (new Embedder())->embed($release->links->get(Platform::APPLE_MUSIC))->html, "\n";
```

```
$ php bare.php
Perspectives Concertantes (2025, ES-DUR)

1. Concerto for Harp and Orchestra in E-Flat Major, Op. 74: I. Allegro Moderato
2. Concerto for Harp and Orchestra in E-Flat Major, Op. 74: II. Tema con Variazioni
3. Concerto for Harp and Orchestra in E-Flat Major, Op. 74: III. Allegro Giocoso
4. Concertino for Harp & Chamber Orchestra, Op. 45: I. Andante
5. Concertino for Harp & Chamber Orchestra, Op. 45: II. Allegretto Vivace
6. Concertino for Harp & Chamber Orchestra, Op. 45: III. Adagio non Troppo
7. Deux Danses pour harpe avec accompagnement d'orchestre: I. Danse sacrée
8. Deux Danses pour harpe avec accompagnement d'orchestre: II. Danse profane

preview: https://audio-ssl.itunes.apple.com/itunes-assets/AudioPreview221/v4/02/3f/df/023fdf56-9772-8b41-3e98-d0985a4b50a6/mzaf_17993483565663500051.plus.aac.p.m4a

label        https://www.es-dur.de
apple_music  https://music.apple.com/de/album/perspectives-concertantes/1793146044
itunes       https://music.apple.com/de/album/perspectives-concertantes/1793146044?app=itunes

<iframe src="https://embed.music.apple.com/de/album/perspectives-concertantes/1793146044" title="Apple Music" width="100%" height="450" style="border:0;border-radius:12px" frameborder="0" loading="lazy" allowfullscreen allow="autoplay *; encrypted-media *; fullscreen *; clipboard-write" sandbox="allow-forms allow-popups allow-same-origin allow-scripts allow-storage-access-by-user-activation allow-top-navigation-by-user-activation"></iframe>
```

(as answered by the iTunes Search API on 2026-10-05, in an empty directory after
`composer require glitchr/omnisong omnisong/itunes`, the package's name then)

No key, no account: the script asks the iTunes Search API once (about 20 calls a minute are
allowed). The pieces:

- a **factory** per catalogue package (`ItunesCatalogFactory`, `OdesliCatalogFactory`), which
  takes the HTTP client to call with - the application's, a `MockHttpClient` in a test - and with
  none given makes its own (`HttpClient::create()`);
- the **registry**, built from the factories and the catalogues' options, by name: `get()`,
  `all()`, `names()`, `has()`, `factories()`;
- the **aggregator**, `Catalog\Catalog`, over the catalogues in the order they are configured:
  `release()`, `links()`, `releases()`, and `$incomplete` after each call;
- the **embedder**, `Player\Embedder`, which turns a link into the platform's own player - no
  call, no key.

No class of a framework is loaded on the way: `Tests/BareTest.php` checks it in a process of its
own, and so does `docker compose run --rm omnisong bare` ([harness](harness.md)).

## One catalogue

A catalogue can be used alone, without the registry or the aggregator:

```php
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Model\Reference;
use Symfony\Component\HttpClient\HttpClient;

$itunes = (new ItunesCatalogFactory(HttpClient::create()))->create(['country' => 'de']);

$release = $itunes->release(Reference::upc('4015372820954'));
$release->tracks[0]->previewUrl;                 // 30 seconds, for an <audio> element
$itunes->releases('Anaëlle Tourret', 10);        // what an artist put out, newest first
$itunes->links(Reference::parse('https://music.apple.com/de/album/perspectives-concertantes/1793146044'));
```

A reference is a URL on any platform, a UPC/EAN (a release), an ISRC (a recording) or a platform's
own id; `Reference::parse()` reads a URL, a UPC/EAN or an ISRC from a string. What a catalogue
cannot read is a `NotSupportedException`: used alone, the caller sees it; under the aggregator, the
next catalogue is asked.

## Several catalogues

```php
use Omnisong\Catalog\Catalog;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Model\Label;
use Omnisong\Model\Reference;
use Omnisong\Odesli\OdesliCatalogFactory;
use Omnisong\Registry;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$registry = new Registry(
    [new OdesliCatalogFactory($http), new ItunesCatalogFactory($http)],
    [
        'odesli' => ['factory' => 'odesli', 'options' => ['api_key' => getenv('ODESLI_API_KEY') ?: null, 'country' => 'DE']],
        'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de']],
    ],
);
$catalog = new Catalog($registry->all());

$release = $catalog->release(Reference::upc('4015372820954'), new Label('ES-DUR', 'https://www.es-dur.de'));
$catalog->incomplete;        // the catalogues that could not be reached: do not cache that half answer
$registry->get('itunes');    // one catalogue, by its name
```

Odesli is configured first, but reads no UPC: the aggregator goes on to iTunes, which finds the
release and its tracks, then asks Odesli again by the iTunes id it learnt and merges the other
platforms' links. The same merge, on the answers the harness keeps in `docker/harness/recorded/`
(iTunes' as answered on 2026-10-03; Odesli's written from what album.link showed that day):

```
$ OMNISONG_AUTOLOAD=vendor/autoload.php php core/docker/harness/bin/bare --recorded
Omnisong in bare PHP: the registry built by hand, no bundle, no container.

  1. odesli
  2. itunes

The release with the UPC 4015372820954, from the answers kept in recorded/:
  Perspectives Concertantes - Anaelle Tourret, NDR Elbphilharmonie Orchester, Vasily Petrenko, Stuttgarter Kammerorchester & Bar Avni
  album of 2025-02-28, ES-DUR
  8 tracks, 8 with a preview; the first: Concerto for Harp and Orchestra in E-Flat Major, Op. 74: I. Allegro Moderato (11:17)
  https://audio-ssl.itunes.apple.com/itunes-assets/AudioPreview221/v4/02/3f/df/023fdf56-9772-8b41-3e98-d0985a4b50a6/mzaf_17993483565663500051.plus.aac.p.m4a
  where it is, the label first:
    label         https://www.es-dur.de
    apple_music   https://music.apple.com/de/album/perspectives-concertantes/1793146044
    tidal         https://listen.tidal.com/album/414051173
    amazon_music  https://music.amazon.com/albums/B0DV5P8V78
    itunes        https://music.apple.com/de/album/perspectives-concertantes/1793146044?app=itunes
    amazon        https://amazon.com/dp/B0DV5P8V78

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, a bridge, Doctrine, Twig): none
```

(the harness's `bare` script, run on 2026-10-26 on the packages' checkouts, PHP 8.4, no network;
`docker compose run --rm omnisong bare --recorded` prints the same). Without a key song.link
answers 401: Odesli is then skipped, `$catalog->incomplete` is `['odesli']`, and the release comes
with iTunes' tracks and Apple's two links.

## In a framework

- **Symfony**: `Omnisong\Bridge\Symfony\OmnisongBundle` registers the factories on the
  application's `http_client`, builds the registry from `config/packages/omnisong.yaml`, autowires
  the aggregator, each catalogue by its name and the embedder, and with Twig gives the templates the
  players: [Symfony](symfony.md).
- **Laravel**: there is no bridge package; a service provider builds the registry and the
  aggregator once: [Laravel](laravel.md).
- **Any other**: build the `Registry` and the `Catalog` once, where the framework builds its
  services (a container definition, a factory), as the scripts above do.

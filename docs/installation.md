# Installation and first calls

```sh
composer require omniscient/omnisong omnisong/itunes  # no key
composer require omnisong/odesli                      # with a key from developers@song.link
```

PHP 8.2 or later.

Omnisong needs no framework. The core requires nothing but `symfony/http-client-contracts`, each
catalogue package `symfony/http-client`: two libraries that stand alone. It runs the same in
plain PHP, in a worker, in Laravel or Slim, and in Symfony, where a bundle does the wiring
([Symfony](symfony.md)).

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

(as answered on 2026-10-05, in an empty directory after `composer require glitchr/omnisong omnisong/itunes`)

No key, no account: the script asks the iTunes Search API - one call; about 20 a minute are
allowed. That is all there is to it:

- a **factory** per catalogue package (`ItunesCatalogFactory`, `OdesliCatalogFactory`), which
  takes the HTTP client to call with - the application's, a `MockHttpClient` in a test; with none
  given it makes its own (`HttpClient::create()`);
- the **registry**, built by hand from the factories and the catalogues' options, by name;
- the **aggregator**, `Catalog\Catalog`, over the catalogues in the order they are configured:
  `release()`, `links()`, `releases()`;
- the **embedder**, which turns a link into the platform's own player - no call, no key.

No class of a framework is loaded on the way - a test of this package checks it in a process of
its own (`Tests/BareTest.php`), and so does `docker compose run --rm omnisong bare`
([harness](harness.md)).

## One catalogue

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

A reference is a URL on any platform, a UPC/EAN (a release), an ISRC (a recording) or a
platform's own id; `Reference::parse()` reads any of them from a string. What a catalogue cannot
answer is a `NotSupportedException`.

## Several catalogues

```php
use Omnisong\Catalog\Catalog;
use Omnisong\Registry;

$registry = new Registry(
    [new OdesliCatalogFactory($http), new ItunesCatalogFactory($http)],
    [
        'odesli' => ['factory' => 'odesli', 'options' => ['api_key' => getenv('ODESLI_API_KEY'), 'country' => 'DE']],
        'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de']],
    ],
);
$catalog = new Catalog($registry->all());

$release = $catalog->release(Reference::upc('4015372820954'), new Label('ES-DUR', 'https://www.es-dur.de'));
$catalog->incomplete;        // the catalogues that could not be reached: do not cache that half answer
$registry->get('itunes');    // one catalogue, by its name
```

iTunes finds the release by its UPC and gives its tracks; Odesli, which reads no UPC, is asked
again by the iTunes id and gives the other platforms. Odesli refuses keyless calls (401): without
a key it is skipped and named in `$catalog->incomplete`.

## In a framework

- **Symfony**: `Omnisong\Bridge\Symfony\OmnisongBundle` registers the factories on the
  application's `http_client`, builds the registry from `config/packages/omnisong.yaml`, autowires
  the aggregator and the embedder and, with Twig, gives the templates the players: see
  [Symfony](symfony.md). Its components (`symfony/config`, `symfony/dependency-injection`,
  `symfony/http-kernel`, `twig/twig`) are not required by this package: a Symfony application
  has them.
- **Any other**: build the `Registry` and the `Catalog` once, where the framework builds its
  services (a service provider, a container definition), as the script above does.

## Errors

| Exception | When |
|---|---|
| `NotSupportedException` | the catalogue does not read that reference, or does not do that |
| `RateLimitedException` | 429 (403 for iTunes): slow down |
| `UnavailableException` | 5xx, a timeout, a network error (the one above extends it): never "nothing" |
| `ProviderException` | any other error answer |
| `InvalidConfigException` | a catalogue not configured, a factory not installed, an option missing |

All implement `Omnisong\Exception\OmnisongException`. Not found is `null` or an empty list.

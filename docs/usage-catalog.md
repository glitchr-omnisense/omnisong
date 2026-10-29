---
title: Catalogues and the aggregator
order: 31
---

# Catalogues and the aggregator

The examples below use `$catalog`, the aggregator over the two catalogues, Odesli first:

```php
use Omnisong\Catalog\Catalog;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Odesli\OdesliCatalogFactory;
use Omnisong\Registry;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$registry = new Registry([new OdesliCatalogFactory($http), new ItunesCatalogFactory($http)], [
    'odesli' => ['factory' => 'odesli', 'options' => ['api_key' => getenv('ODESLI_API_KEY') ?: null, 'country' => 'DE']],
    'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de']],
]);
$catalog = new Catalog($registry->all());
```

Each catalogue can also be called alone (`$registry->get('itunes')->release(...)`): then a
`NotSupportedException` or an `UnavailableException` reaches the caller instead of being absorbed.

## A release

```php
use Omnisong\Model\Label;
use Omnisong\Model\Reference;

$release = $catalog->release(
    Reference::upc('4015372820954'),
    new Label('ES-DUR', 'https://www.es-dur.de', 'https://www.es-dur.de/shop/perspectives'),
);

if (null === $release) {
    // no catalogue knows it (or every one that could was unavailable: check $catalog->incomplete)
}
$release->title;          // Perspectives Concertantes
$release->tracks;         // list<Track>, each with its previewUrl
$release->links;          // label, shop, then every platform
```

What the aggregator does, in order:

1. Each catalogue is asked `release()` in turn. Before the first answer, by the reference given.
   Once one catalogue found the release, the next ones are asked by the reference given and then by
   what was learnt: the release's UPC first when the reference had none, then its id on each
   platform it has one for (`Release::$ids`), as `Reference::id()` of type `album` - or `song` for a
   single without tracks. A catalogue is asked by the first of these it can read; its first answer
   that is not a `NotSupportedException` is taken, even `null`.
2. Each answer is merged into the first (`Release::merge()`): the first catalogue's title and type
   stay, what it did not know is filled, and its tracks get the previews the other's tracks have.
3. Nobody found it: `null`.
4. The links: the release's own (a store's canonical URL beats a redirect), then every catalogue's
   `links()`, asked as in [Links](#links) by the reference given and what was learnt.
5. The label given replaces the release's; the label's links go first.

With the recorded answers of the [harness](harness.md) (iTunes' of 2026-10-03, Odesli's written
from album.link), the calls the script above makes are:

```
GET https://itunes.apple.com/lookup?upc=4015372820954&entity=song&country=de&limit=200
GET https://api.song.link/v1-alpha.1/links?platform=itunes&type=album&id=1793146044&userCountry=DE&key=...
```

Odesli is first, but cannot read a UPC: its `release()` throws `NotSupportedException` without a
call, and it has no second turn - every reference it could read was learnt after it. The release is
iTunes'; Odesli, asked for links by the iTunes id iTunes gave, adds the other platforms. The
result:

```
Perspectives Concertantes | album | 4015372820954 | ES-DUR | 8 tracks
  label         ES-DUR         https://www.es-dur.de
  shop          ES-DUR         https://www.es-dur.de/shop/perspectives
  apple_music   Apple Music    https://music.apple.com/de/album/perspectives-concertantes/1793146044
  tidal         Tidal          https://listen.tidal.com/album/414051173
  amazon_music  Amazon Music   https://music.amazon.com/albums/B0DV5P8V78
  itunes        iTunes Store   https://music.apple.com/de/album/perspectives-concertantes/1793146044?app=itunes
  amazon        Amazon         https://amazon.com/dp/B0DV5P8V78
incomplete: []
```

(run on 2026-10-26 on a `MockHttpClient` serving those files, PHP 8.4). Without a key, song.link
answers 401: the same calls, Odesli named in `incomplete` (`["odesli"]`), and only the label, shop,
Apple Music and iTunes Store links.

The catalogues keep what they were answered for the life of the object (by query): the
second `links()` that `release()` makes of iTunes, by the UPC it was first asked, calls nothing.

## Links

```php
$links = $catalog->links(Reference::parse('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy'), $label);

foreach ($links as $value => $link) {            // 'label' => PlatformLink, 'spotify' => ...
    echo $link->title(), ': ', $link->url, "\n";
}
$links->get(Platform::DEEZER)?->url;
$links->toArray();                               // ['label' => 'https://...', 'spotify' => 'https://...']: store it
PlatformLinks::fromArray($stored, 'ES-DUR');     // and read it back
```

Every catalogue is asked `links()` by the reference. One that can read it adds its links; when
several give a link to the same platform, the catalogue asked first wins. A catalogue that can read
none of the references (Odesli and a UPC or an ISRC) is asked again, once every other catalogue has
answered, by the platform ids those links carry - in the links' order, so Apple Music's id before
the iTunes Store's - as `Reference::id()` of an album: those links name the release that holds the recording (iTunes
answers an ISRC with its album's links), so `links(Reference::isrc(...))` asks Odesli
`?platform=appleMusic&type=album&id=1793146044` and gives the album's links on every platform.

The `$label` argument is optional; given, its links go first ([Concepts](concepts.md#links)).

## An artist's releases

```php
foreach ($catalog->releases('Anaëlle Tourret', limit: 10, country: 'de') as $release) {
    echo $release->releasedAt?->format('Y'), ' ', $release->title, ' (', $release->type, ")\n";
}
```

The catalogues are asked in turn; the first non-empty answer is the answer, not merged with the
others'. Odesli does not list releases (`NotSupportedException`, skipped); iTunes searches albums by
artist name (`attribute=artistTerm`), newest first, without their tracks: ask `release()` for one of
them to get its tracks. The search is by name, so it may return another artist of the same name.

## A half answer

```php
$release = $catalog->release($reference);
if ($catalog->incomplete) {
    // ['odesli']: a catalogue was down, refused the key or asked to slow down.
    // Show what came, do not cache it as the answer.
}
```

A catalogue's `UnavailableException` (and `RateLimitedException`, its child) is caught: the
catalogue is skipped for the rest of the call and its name added to `$incomplete`. A
`ProviderException` that is not one of those (iTunes or Odesli answering 400) is **not** caught: it
reaches the caller. `$incomplete` is reset at the start of each `links()`, `release()` and
`releases()`.

## References

| To ask about | Build | Read by |
|---|---|---|
| a release by its barcode | `Reference::upc('4015372820954')` | iTunes |
| a recording | `Reference::isrc('DEA122500001')` | iTunes (its album) |
| anything with a URL | `Reference::url('https://...')` or `Reference::parse('https://...')` | Odesli (any platform); iTunes (Apple URLs with an id) |
| a platform's id | `Reference::id(Platform::ITUNES, '1793146044')`, `Reference::id(Platform::SPOTIFY, '4uLU6hMCjMI75M1A2tKUQC', Reference::SONG)` | iTunes (`itunes`, `apple_music`); Odesli (16 platforms) |
| a string a user typed | `Reference::parse($value)` | the kind it reads |

The `country` argument of each constructor is the store asked: it overrides the catalogue's
configured country for that call (iTunes' `country`, Odesli's `userCountry`).

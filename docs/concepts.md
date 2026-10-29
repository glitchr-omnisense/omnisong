---
title: Concepts
order: 20
---

# Concepts

## Catalogues

A catalogue is a service that knows recordings, behind `Omnisong\Catalog\CatalogInterface`:

```php
interface CatalogInterface
{
    public function getName(): string;                                                        // "itunes", "odesli"...
    public function links(Reference $reference): PlatformLinks;                               // the same recording on every platform it knows
    public function release(Reference $reference): ?Release;                                  // the release the reference names, null when unknown
    public function releases(string $artist, int $limit = 50, ?string $country = null): array; // list<Release>, newest first
}
```

The rule every catalogue follows: answer what it can, throw `NotSupportedException` for the rest -
a kind of reference it cannot read, or an operation it does not do. Unknown is not an error:
`links()` gives an empty `PlatformLinks`, `release()` gives `null`, `releases()` an empty list. A
service that cannot be reached, is down, refuses the key or asks to slow down is an
`UnavailableException` (or its child `RateLimitedException`), never "unknown": that is what lets a
caller tell a real "not found" from a half answer ([Errors](errors.md)).

## Factories and their Config

A catalogue is built by its factory, `Omnisong\Catalog\CatalogFactoryInterface`:

| Method | |
|---|---|
| `getName(): string` | the name catalogues are configured with: `itunes`, `odesli` |
| `create(array $options = []): CatalogInterface` | a catalogue built from those options |

Both packages extend `Omnisong\Catalog\CatalogFactory`, the Omnibus way at its simplest:

| Member | |
|---|---|
| `__construct(?HttpClientInterface $http = null)` | the HTTP client the catalogue calls with; null: the catalogue package makes its own (`HttpClient::create()`) |
| `getName()` | the `omnisong.factory_name` its `populate()` sets |
| `create($options)` | `createConfig($options)`, checks that every key of `omnisong.required_options` is filled, then `build()` |
| `createConfig($options): Config` | the options given, with the factory's defaults under them |
| `populate(Config $c)` (abstract, protected) | sets the name, the required options and the defaults of the others, with `$c->defaults([...])` |
| `build(Config $c)` (abstract, protected) | the catalogue, from a `Config` that holds everything it needs |

`Omnisong\Config` is an `ArrayObject` of the options:

| Method | |
|---|---|
| `defaults(array $defaults): self` | sets each key not set yet: what the caller gave wins, even `null` |
| `validateNotEmpty(array $keys): void` | throws `InvalidConfigException` naming the keys that are unset or null, `''` or `[]`: `The "<factory name>" catalogue needs: <key>, <key>.` |
| `get(string $key, mixed $default = null)` | the value, or the default when the key is not set |

Two keys are the factory's own, not options: `omnisong.factory_name` and `omnisong.required_options`
(a list of option names). Neither shipped catalogue has a required option.

## The registry

`Omnisong\Registry` holds the catalogues by name:

```php
$registry = new Registry(
    [new OdesliCatalogFactory($http), new ItunesCatalogFactory($http)],   // iterable<CatalogFactoryInterface>
    [                                                                       // name => factory and options
        'odesli' => ['factory' => 'odesli', 'options' => ['api_key' => '...']],
        'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de']],
    ],
);
```

| Method | |
|---|---|
| `get(string $name): CatalogInterface` | the catalogue, built once with its factory's `create($options)` and kept. A name not configured, or a factory not given, is an `InvalidConfigException`: `No "spotify" catalogue; configured: odesli, itunes.`, `No "deezer" factory for the "x" catalogue; installed: odesli, itunes.` |
| `has(string $name): bool` | whether the name is configured (not whether it can be built) |
| `names(): list<string>` | the configured names, in the configured order |
| `all(): array<string, CatalogInterface>` | every configured catalogue, built, in the configured order |
| `factories(): list<string>` | the factories' names: what `factory` may name |

The configured order matters: it is the order the aggregator asks the catalogues in. A factory given
twice under the same name: the last one wins.

## The aggregator

`Omnisong\Catalog\Catalog` implements `CatalogInterface` over a list of catalogues, asked in the
order given (`new Catalog($registry->all())`). Its `getName()` is `catalog`.

| Member | |
|---|---|
| `__construct(iterable $catalogs)` | the catalogues, in the order they are asked |
| `names(): list<string>` | their names, in that order |
| `links(Reference $reference, ?Label $label = null): PlatformLinks` | every catalogue's links, merged; the label's first when given |
| `release(Reference $reference, ?Label $label = null): ?Release` | the release every catalogue knows, merged, with every catalogue's links |
| `releases(string $artist, int $limit = 50, ?string $country = null): list<Release>` | the first catalogue's non-empty answer |
| `public array $incomplete` | the names of the catalogues skipped as unavailable during the last call, reset at the start of each |

How each call asks and merges is in [Catalogues and the aggregator](usage-catalog.md).

## References

`Omnisong\Model\Reference` is what a catalogue is asked about. One of its kinds is enough; it is
built by its named constructors only (the constructor is private):

| Constructor | Sets | Normalised |
|---|---|---|
| `Reference::url(string $url, ?string $country = null)` | `url`, and `platform` from `Platform::fromUrl($url)` (null when unknown) | no |
| `Reference::isrc(string $isrc, ?string $country = null)` | `isrc`, `type: song` | everything but letters and digits removed, upper case: `DE-A12-25-00001` is `DEA122500001` |
| `Reference::upc(string $upc, ?string $country = null)` | `upc`, `type: album` | everything but digits removed: `0 4015372 820954` is `04015372820954` |
| `Reference::id(Platform $platform, string $id, string $type = Reference::ALBUM, ?string $country = null)` | `platform`, `id`, `type` | no |
| `Reference::parse(string $value, ?string $country = null)` | one of the above, read from a loose string | trimmed |

`parse()` reads, in this order: `http://` or `https://` (any case) is a URL; 12 to 14 digits is a
UPC/EAN; two letters, three letters or digits and seven digits (dashes ignored) is an ISRC. Anything
else is an `\InvalidArgumentException` (`"hello" is neither a URL, a UPC/EAN nor an ISRC.`), and so
is a UPC written with spaces: `parse()` does not strip them, `upc()` does.

`Reference::parse()` throws an `Omnisong\Exception\InvalidReferenceException` for a value it cannot read: an `OmnisongException`, and an `\InvalidArgumentException` still.

| Property | Type | |
|---|---|---|
| `url` | `?string` | a URL on any platform |
| `isrc` | `?string` | an ISRC (a recording) |
| `upc` | `?string` | a UPC or EAN (a release) |
| `platform` | `?Platform` | the platform of `url` or of `id` |
| `id` | `?string` | the platform's own id |
| `type` | `?string` | `Reference::SONG` (`'song'`) or `Reference::ALBUM` (`'album'`), when known; a URL's is null |
| `country` | `?string` | ISO 3166-1 alpha-2: the store the links should point at; over the catalogue's configured country |

`withCountry(?string $country)` gives a copy for another store. `(string) $reference` is the URL,
else the ISRC, else the UPC, else `platform:id` (`itunes:1793146044`).

## Releases and tracks

`Omnisong\Model\Release` is an album, a single or an EP. Immutable: every property is `readonly`.

| Property | Type | Default | |
|---|---|---|---|
| `title` | `string` | - | without the store's " - Single" / " - EP" suffix |
| `artist` | `?string` | `null` | as the catalogue writes it |
| `label` | `?Label` | `null` | the label it came out on |
| `upc` | `?string` | `null` | the UPC/EAN that names it |
| `releasedAt` | `?\DateTimeImmutable` | `null` | |
| `coverUrl` | `?string` | `null` | the cover image (iTunes: 1200 x 1200) |
| `type` | `string` | `Release::ALBUM` | `Release::ALBUM` (`'album'`), `Release::SINGLE` (`'single'`) or `Release::EP` (`'ep'`) |
| `tracks` | `list<Track>` | `[]` | iTunes gives them by disc, then position |
| `links` | `?PlatformLinks` | `null` | every platform it is on, the label's first |
| `genre` | `?string` | `null` | the store's primary genre |
| `trackCount` | `?int` | `null` | as the catalogue says, or the number of tracks set by `withTracks()` |
| `ids` | `array<string, string>` | `[]` | a platform's value => its id for the release (`['itunes' => '1793146044', ...]`) |
| `copyright` | `?string` | `null` | the store's copyright line (`℗ 2025 ES-DUR`) |

| Method | |
|---|---|
| `withLinks(PlatformLinks $links)` | a copy with these links |
| `withTracks(list<Track> $tracks)` | a copy with these tracks; `trackCount` becomes their number when it was null |
| `withLabel(?Label $label)` | a copy with this label |
| `merge(?Release $other)` | what another catalogue knew that this one did not, below |

`merge()` keeps this release's title and type, fills every other null from `$other`, adds the ids
`$other` has and this one has not, and merges the links (this one's first). The tracks: when only
one side has some, those; when both have, this side's, each given the preview of its twin on the
other side when it has none - the twin found by ISRC first, else by the same place in the list.

`Omnisong\Model\Track` is one recording of a release:

| Property | Type | Default | |
|---|---|---|---|
| `title` | `string` | - | |
| `position` | `?int` | `null` | its number on the disc |
| `disc` | `?int` | `null` | |
| `duration` | `?int` | `null` | seconds |
| `isrc` | `?string` | `null` | the ISRC that names it everywhere |
| `previewUrl` | `?string` | `null` | the preview a catalogue offers (iTunes: 30 seconds of AAC, licensed for promotion next to a store link) |
| `artist` | `?string` | `null` | |
| `composer` | `?string` | `null` | |
| `ids` | `array<string, string>` | `[]` | a platform's value => its id for the track |
| `url` | `?string` | `null` | the track's page on the store |

`withPreview(?string $previewUrl)` gives a copy with another preview.

## Labels

`Omnisong\Model\Label` is the label a release came out on, who a site shows first:

| Property | Type | |
|---|---|---|
| `name` | `string` | `ES-DUR` |
| `url` | `?string` | the label's site, or the release's page on it |
| `shopUrl` | `?string` | where the label sells the release |

A catalogue gives a label by name only (iTunes reads it from the copyright line); the site's own
`Label`, given to `links()` or `release()`, carries the URLs and replaces it.

## Links

`Omnisong\Model\PlatformLink` is one place the recording is:

| Property | Type | |
|---|---|---|
| `platform` | `Platform` | |
| `url` | `string` | |
| `id` | `?string` | the platform's own id, when known (Odesli gives one per platform, iTunes the collection id) |
| `title` | `?string` | for `LABEL` and `SHOP`: the name to show (`ES-DUR`) |
| `country` | `?string` | the store the URL points at, when the catalogue says |

`title()` is `title`, else the platform's `label()`.

`Omnisong\Model\PlatformLinks` holds the links of one recording, **one per platform**, in the order a
site shows them: by `Platform::rank()`, then by the platform's value. It is immutable (every method
that changes it gives a new one), `Countable`, and iterable as platform value => `PlatformLink`.

| Method | |
|---|---|
| `new PlatformLinks(iterable $links = [])` | the first link of each platform is kept, the others dropped |
| `PlatformLinks::none()` | an empty one |
| `get(Platform $platform): ?PlatformLink`, `has(Platform $platform): bool` | |
| `first(): ?PlatformLink` | the first in order: the label's when there is one |
| `with(PlatformLink $link)` | this link added, **replacing** the platform's link when there was one |
| `withLabel(Label $label)` | a `LABEL` link to the label's `url` (else its `shopUrl`) named after it, and a `SHOP` link to its `shopUrl` when it differs; both replace the links of those platforms. A label with neither URL changes nothing |
| `merge(?PlatformLinks $other)` | both sides' links; a platform both have keeps **this** side's |
| `all(): list<PlatformLink>` | in order |
| `embeddable(): list<PlatformLink>` | those the embedder has a player for |
| `toArray(): array<string, string>` | platform value => URL, in order: what a site stores |
| `PlatformLinks::fromArray(array $urls, ?string $labelName = null)` | back from that array; a key that is not a platform's value, a URL that is not a non-empty string are skipped; `$labelName` becomes the title of the `label` and `shop` links |

## Platforms

`Omnisong\Platform` is a string-backed enum of the places a recording can be listened to or bought,
in `rank()` order:

| Case | Value | `label()` | `rank()` | `embeddable()` | song.link key |
|---|---|---|---|---|---|
| `LABEL` | `label` | Label | 0 | - | - |
| `SHOP` | `shop` | Shop | 1 | - | - |
| `BANDCAMP` | `bandcamp` | Bandcamp | 2 | - | `bandcamp` |
| `SPOTIFY` | `spotify` | Spotify | 10 | yes | `spotify` |
| `APPLE_MUSIC` | `apple_music` | Apple Music | 11 | yes | `appleMusic` |
| `YOUTUBE_MUSIC` | `youtube_music` | YouTube Music | 12 | yes | `youtubeMusic` |
| `YOUTUBE` | `youtube` | YouTube | 13 | yes | `youtube` |
| `DEEZER` | `deezer` | Deezer | 14 | yes | `deezer` |
| `QOBUZ` | `qobuz` | Qobuz | 15 | - | - |
| `IDAGIO` | `idagio` | Idagio | 16 | - | - |
| `TIDAL` | `tidal` | Tidal | 17 | - | `tidal` |
| `AMAZON_MUSIC` | `amazon_music` | Amazon Music | 18 | - | `amazonMusic` |
| `ITUNES` | `itunes` | iTunes Store | 20 | - | `itunes` |
| `AMAZON` | `amazon` | Amazon | 21 | - | `amazonStore` |
| `PRESTO` | `presto` | Presto Music | 22 | - | - |
| `HIGHRESAUDIO` | `highresaudio` | HIGHRESAUDIO | 23 | - | - |
| `SOUNDCLOUD` | `soundcloud` | SoundCloud | 30 | yes | `soundcloud` |
| `NAPSTER` | `napster` | Napster | 31 | - | `napster` |
| `PANDORA` | `pandora` | Pandora | 32 | - | `pandora` |
| `ANGHAMI` | `anghami` | Anghami | 33 | - | `anghami` |
| `BOOMPLAY` | `boomplay` | Boomplay | 34 | - | `boomplay` |
| `AUDIOMACK` | `audiomack` | Audiomack | 35 | - | `audiomack` |
| `AUDIUS` | `audius` | Audius | 36 | - | `audius` |
| `OTHER` | `other` | Other | 99 | - | - |

(generated from the enum on 2026-10-26). The rank puts the label first, then the shops and
Bandcamp, then the streamers by their weight in classical music, then the stores, then the rest.

- `Platform::fromOdesli(string $key): ?Platform` maps song.link's `linksByPlatform` keys (last
  column); any other key is null.
- `Platform::fromUrl(string $url): ?Platform` reads the URL's host, without a leading `www.`,
  `open.`, `music.`, `listen.`, `play.` or `geo.`: `spotify.com`; `apple.com` when the path has
  `/album/`, `/song/`, `/playlist/`, `/artist/` or `/music-video/` (Apple Music; another Apple page
  is null); `youtube.com` and `youtu.be` (YouTube Music when the host starts with `music.`);
  `deezer.com`, `qobuz.com`, `idagio.com`, `tidal.com`; `amazon.com`, `.fr`, `.de` and `.co.uk`
  (Amazon Music when the URL has `/music/` or `/albums/`, else Amazon); `prestomusic.com`,
  `highresaudio.com`, `bandcamp.com` (any subdomain), `soundcloud.com`, `napster.com`,
  `pandora.com`. Anghami, Boomplay, Audiomack and Audius URLs, and any other host, are null.

`LABEL`, `SHOP`, `QOBUZ`, `IDAGIO`, `PRESTO`, `HIGHRESAUDIO` and `OTHER` come from no catalogue:
the first two from a `Label`, the others from a URL the site gives (`Reference::url()`,
`PlatformLinks::fromArray()`).

## Players

| Class | |
|---|---|
| `Omnisong\Player\EmbedderInterface` | `supports(Platform $platform): bool`; `embed(PlatformLink $link, ?EmbedOptions $options = null): ?Embed`, null when the URL is not one the platform embeds |
| `Omnisong\Player\Embedder` | the core's implementation, from the platforms' public embed URLs: `new Embedder(?EmbedOptions $defaults = null)`; `supports()` is `Platform::embeddable()` |
| `Omnisong\Player\EmbedOptions` | how an embed looks, below |
| `Omnisong\Model\Embed` | the result: `platform` (`Platform`), `html` (the iframe), `src` (its URL: what a Content-Security-Policy's `frame-src` must allow), `width` (always `100`: the iframe is 100 % wide), `height` (pixels) |

`EmbedOptions`, all `readonly`:

| Property | Type | Default | |
|---|---|---|---|
| `theme` | `string` | `EmbedOptions::LIGHT` (`'light'`) | `EmbedOptions::DARK` (`'dark'`) for a dark page; any other value is light |
| `compact` | `bool` | `false` | the smaller player |
| `autoplay` | `bool` | `false` | read by YouTube and SoundCloud only |
| `country` | `?string` | `null` | ISO 3166-1 alpha-2: the store Apple Music opens, over the one in its URL |
| `color` | `?string` | `null` | a hex colour, for SoundCloud |

`dark(): bool` is `theme === 'dark'`. What each platform does with them: [Players](usage-players.md).

## Declared, not used

Two models are part of the package and used by none of its classes nor by either catalogue:

- `Omnisong\Model\Artist`: `name` (`string`), `ids` (platform value => id), `url`, `imageUrl` -
  a performer as a catalogue would name it. `releases()` takes the artist's name as a string.
- `Omnisong\Model\Source`: `url`, `kind` (`Source::FILE` `'file'` or `Source::PREVIEW`
  `'preview'`), `mime`, `duration` (seconds) - something an `<audio>` element can play. A track's
  preview is its `previewUrl` string.

An application may use them for its own data; no catalogue returns them.

# omnisense/omnisong

Omnisong is one PHP contract for listening to a musician's catalogue. A site asks every catalogue
service the same three questions - where is this recording, what is on this release, what did this
artist put out - and gets the same answers back: `PlatformLinks`, a `Release` with its `Track`s and
their previews, a list of `Release`s. Each service is a package of its own (`omnisong/itunes`,
`omnisong/odesli`) that turns those questions into its API calls; the core's aggregator,
`Catalog\Catalog`, asks every configured catalogue in turn and merges what they say.

```php
$release = $catalog->release(Reference::upc('4015372820954'), new Label('ES-DUR', 'https://www.es-dur.de'));

$release->tracks[0]->previewUrl;                              // 30 seconds, for an <audio> element
$release->links->first();                                     // ES-DUR, then the stores and streamers in order
$embedder->embed($release->links->get(Platform::SPOTIFY));    // the platform's own player, as an iframe
$catalog->releases('Anaëlle Tourret');                        // what an artist put out, newest first
$catalog->incomplete;                                         // the catalogues that could not be reached
```

It is about listening, not publishing (publishing is `omnisense/omnipost`'s job), and it puts the
label first: a musician's site shows "released by ES-DUR, buy it there" before "listen on Spotify".
`PlatformLinks` keep that order (`Platform::rank()`). Besides the catalogues, the core holds the
`Player\Embedder`, which turns a link into the platform's own iframe from its public embed URL, with
no key and no call.

The core needs no framework: it requires `symfony/http-client-contracts` and nothing else; each
catalogue package requires `symfony/http-client`, a library that stands alone. A Symfony bundle and
a Twig extension are included for applications that want the wiring done ([Symfony](symfony.md)).

Published as `omnisense/omnisong`, formerly `glitchr/omnisong` and `omniscient/omnisong`: the package
`replace`s both names, so a `composer.json` that still requires one of them resolves to this package
and never installs two. The namespace is `Omnisong\`; the catalogues keep the `omnisong/` vendor and
the `Omnisong\<Catalogue>\` namespace.

## When to use it

- A musician's or a label's site that shows its releases with their tracks, 30-second previews,
  covers, and the links to every store and streaming service, the label's own shop first.
- A page that plays a release with the platform's own player (Spotify, Apple Music, Deezer, YouTube,
  SoundCloud) without an SDK or a key.
- A discography read from a store's catalogue (`releases($artist)`), rather than typed by hand.

It does not host or stream audio, does not read play counts or any account's data, and stores
nothing: caching the answers (they are remote and rate limited) is the application's job, and an
answer the aggregator marks `incomplete` must not be cached.

## Catalogues

Two packages. The capabilities are what each catalogue's code answers (read from its source, and
checked by its tests on recorded answers); "no" means the call throws `NotSupportedException`, which
the aggregator treats as "ask the next one".

| Package | Name | Key | `links()` | `release()` | `releases()` | References read |
|---|---|---|---|---|---|---|
| [`omnisong/itunes`](https://github.com/glitchr-omnisense/omnisong-itunes) | `itunes` | none | Apple Music and iTunes Store only | yes: tracks, previews, cover, label, genre | yes, by artist name, newest first | UPC/EAN, ISRC, Apple Music / iTunes URL, an `itunes` or `apple_music` id |
| [`omnisong/odesli`](https://github.com/glitchr-omnisense/omnisong-odesli) | `odesli` | yes, since song.link withdrew keyless access | the 17 song.link platforms Omnisong maps | title, artist, cover and links; no tracks | no | a URL on any platform, the id of one of 16 platforms |

Neither reads what the other is best at, which is why the aggregator exists: iTunes finds a release
by its UPC and gives its tracks; Odesli, which reads no UPC, is asked again by the iTunes id the
first one found and gives the other platforms ([Catalogues and the aggregator](usage-catalog.md)).

The players are the core's, not a catalogue's: Spotify, Apple Music, Deezer, YouTube, YouTube Music
and SoundCloud ([Players](usage-players.md)).

## How this manual is organised

- [Installation](installation.md): requirements, a first script in plain PHP, several catalogues.
- [Symfony](symfony.md) and [Laravel](laravel.md): wiring the registry, the aggregator and the
  embedder in a framework; the Twig functions.
- [Concepts](concepts.md): catalogues, factories, the registry, the aggregator, every model and the
  `Platform` enum.
- Usage: [catalogues and the aggregator](usage-catalog.md), [players](usage-players.md).
- [Configuration reference](configuration.md): every option of every factory, of the registry and
  of the embedder.
- [Errors](errors.md): the exceptions and when each is thrown.
- [Testing](testing.md): testing an application that uses Omnisong.
- [The Docker harness](harness.md): every catalogue installed, a console to call them.
- [Writing a catalogue](providers.md).
- [Verified, and not](verified.md).

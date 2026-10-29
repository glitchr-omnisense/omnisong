---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

Run on 2026-10-26 on the three packages' checkouts (the workspace's autoloader), PHP 8.4.27,
PHPUnit 11.5.57, no network: 51 tests, 373 assertions, green.

- The core (`Tests/`): `Reference` (URLs, UPC/EAN, ISRC, ids, what `parse()` refuses), `Platform`
  (rank, names, players, URLs, song.link's keys), `PlatformLinks` (order, the label and its shop
  first, merges, storage as an array), `Release::merge()` (nulls filled, previews matched by ISRC
  then by place), the aggregator (`CatalogTest`: each catalogue's share, the UPC learnt asked next,
  a catalogue down skipped and named in `$incomplete`, unknown everywhere is null, an artist's
  releases from the first catalogue that has some), the `Embedder` (each platform, the dark theme,
  the iframe lazy, sandboxed and escaped), the Symfony bundle (`Tests/Bridge/OmnisongBundleTest.php`:
  a kernel boots, the catalogues are asked in the configured order, one catalogue by its argument
  name, the embedder's theme, the Twig functions), and the plain-PHP run with no framework class
  loaded (`Tests/BareTest.php`).
- `omnisong/itunes`: a UPC gives the release with its tracks and previews; an Apple Music URL or its
  id the same release; a track's id or an ISRC its album; the links are Apple's; an artist's albums
  newest first; what is not Apple's is not read; 403, 429 and 5xx are unavailable - on answers
  recorded on 2026-10-03.
- `omnisong/odesli`: a URL gives the album on every platform; a platform's id is asked by platform,
  type and id; the release is title, artist, cover and links without tracks; neither a UPC nor an
  ISRC is read; unknown is empty; 429 says when to come back; down, unreachable or without its key
  is unavailable, naming the key; any other refusal is the provider's error - on an answer written
  from what album.link showed on 2026-10-03.

The examples of these pages were run on 2026-10-26 on the same checkouts, with a `MockHttpClient`
serving the recorded answers: the embedder's URLs and heights, the aggregator's calls and merge,
the failures' exceptions, an application's own catalogue, a test case.

## What is not verified

- That the services answer today as recorded. iTunes was last called live for these manuals'
  first version (2026-10-05, the [installation](installation.md) script). song.link's API was
  never answered with a key: it answers 401 `PUBLIC_API_ACCESS_DEPRECATED` without one (seen
  2026-10-03), and the Odesli fixture is written from album.link's page, not from an API answer.
- That each player URL plays: the embed URLs are built from the platforms' public embed formats;
  the tests check the URLs, not the players in a browser.
- The [Laravel](laravel.md) page was written from the API; it was not run in a Laravel
  application.
- The known defects found while writing these pages: `Reference::parse()`'s exception outside the
  `OmnisongException` family ([Concepts](concepts.md#references)), Odesli asked `type=song` with an
  album's id for an ISRC ([Catalogues](usage-catalog.md#links)), SoundCloud's colour encoded twice
  ([Players](usage-players.md#per-platform)), `Retry-After` as a date read as 0
  ([Errors](errors.md#properties-and-messages)). Fixed since, each with its test: the exception, the ISRC,
  the colour, `Retry-After`.

## Responsibility

`omnisense/omnisong` and its `omnisong/*` catalogues are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (keys, promotional use of previews and attribution, rate limits), named with a link in the
README of each catalogue. The MIT licence covers this code only and grants no right to any service
or to its data; whoever runs these packages complies with those terms.

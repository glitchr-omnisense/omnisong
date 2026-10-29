---
title: Configuration reference
order: 40
---

# Configuration reference

## The registry

```php
new Registry(iterable $factories, array $config);
```

| Key | Type | Required | |
|---|---|---|---|
| `<name>` | the catalogue's name, a string | - | how it is asked for: `get('<name>')`, and in Symfony the argument's name. The configured order is the aggregator's. `$incomplete` and `Catalog::names()` name a catalogue by its own `getName()` (`itunes`), not by this name |
| `<name>.factory` | string | yes | a factory's `getName()`: `itunes`, `odesli`, or an application's own |
| `<name>.options` | map | no, default `[]` | handed to the factory's `create()` |

The same tree is the Symfony bundle's `omnisong.catalogs` ([Symfony](symfony.md#configuration)).
The same factory can serve several catalogues - two iTunes stores, for example:

```php
new Registry([new ItunesCatalogFactory($http)], [
    'itunes_de' => ['factory' => 'itunes', 'options' => ['country' => 'de']],
    'itunes_fr' => ['factory' => 'itunes', 'options' => ['country' => 'fr']],
]);
```

Both are named `itunes` by the aggregator, in `$incomplete` too.

## iTunes (`itunes`)

From `Omnisong\Itunes\ItunesCatalogFactory::populate()` and `build()`:

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `country` | string | `us` | no | the store asked (ISO 3166-1 alpha-2, sent in lower case): what is sold there, in its language. Empty or null is `us`. A reference's own `country` overrides it for that call |
| `base_uri` | string | `https://itunes.apple.com/` | no | the API's root; `lookup` and `search` are appended. Empty or null is the default |

## Odesli (`odesli`)

From `Omnisong\Odesli\OdesliCatalogFactory::populate()` and `build()`:

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string or null | `null` | no (but see below) | sent as `key`; empty is null, and then no key is sent |
| `country` | string or null | `null` | no | sent as `userCountry` (upper case): the store the links point at. Null: song.link's default (US). A reference's own `country` overrides it |
| `base_uri` | string | `https://api.song.link/v1-alpha.1/` | no | the API's root; `links` is appended. Empty or null is the default |

`api_key` is not declared required, so a catalogue without one is built - but song.link answers 401
without a key since it withdrew keyless access (seen on 2026-10-03), and every call is then an
`UnavailableException`: the aggregator skips Odesli and names it in `$incomplete`.

Neither factory reads any other option: an unknown key is ignored, not refused.

## The embedder

```php
new Embedder(?EmbedOptions $defaults = null);
new EmbedOptions(string $theme = 'light', bool $compact = false, bool $autoplay = false, ?string $country = null, ?string $color = null);
```

| Option | Type | Default | |
|---|---|---|---|
| `theme` | string | `light` | `dark` (`EmbedOptions::DARK`) for Spotify, Apple Music and Deezer's dark players; any other value is light |
| `compact` | bool | `false` | the smaller player ([heights](usage-players.md#per-platform)) |
| `autoplay` | bool | `false` | YouTube (`autoplay=1`) and SoundCloud (`auto_play=true`) only |
| `country` | string or null | `null` | Apple Music: the store in the embed URL, over the one in the link |
| `color` | string or null | `null` | SoundCloud: a hex colour, with or without `#` |

In Symfony, `omnisong.player.theme` and `omnisong.player.country` set the embedder's defaults;
`compact`, `autoplay` and `color` have no key there ([Symfony](symfony.md#configuration)).

## Environment variables

The packages read no environment variable themselves. The names used in these pages and in the
harness are conventions:

| Variable | Used for |
|---|---|
| `ODESLI_API_KEY` | Odesli's `api_key` (`%env(default::ODESLI_API_KEY)%` in Symfony: empty when unset) |
| `ODESLI_COUNTRY`, `ITUNES_COUNTRY` | the harness's `country` options ([harness](harness.md)) |
| `OMNISONG_PLUGINS`, `OMNISONG_AUTOLOAD` | the harness only |

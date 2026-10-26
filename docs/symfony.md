---
title: Symfony
order: 15
---

# Symfony

Omnisong runs without a framework ([Installation](installation.md)); in a Symfony application its
bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` (6.4, 7 or 8), and `twig/twig` for the template functions - are not required
by `omnisense/omnisong`: the application has them, and nothing of them is loaded outside Symfony.
The bundle is part of the core (`Omnisong\Bridge\Symfony\OmnisongBundle`); omnibridge has no
Omnisong bridge, and none is needed.

## Registering the bundle

There is no Flex recipe: add the bundle by hand.

```php
// config/bundles.php
return [
    // ...
    Omnisong\Bridge\Symfony\OmnisongBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/omnisong.yaml
omnisong:
    catalogs:            # by name, in the order the aggregator asks them
        odesli: { factory: odesli, options: { api_key: '%env(default::ODESLI_API_KEY)%', country: 'DE' } }
        itunes: { factory: itunes, options: { country: 'de' } }
    player:
        theme: light     # light|dark, the Embedder's default
        country: ~       # overrides the store Apple Music opens
```

The whole tree, from the bundle's `configure()`:

| Key | Type | Default | |
|---|---|---|---|
| `omnisong.catalogs` | map, keyed by the catalogue's name | `{}` | the catalogues, **in the order the aggregator asks them** |
| `omnisong.catalogs.<name>.factory` | string, required, not empty | - | a factory's name: `itunes`, `odesli`, or an application's own factory's `getName()` |
| `omnisong.catalogs.<name>.options` | any value (a map) | `[]` | handed as they are to the factory's `create()` ([Configuration](configuration.md)) |
| `omnisong.player.theme` | enum `light`, `dark` | `light` | the embedder's default theme |
| `omnisong.player.country` | string or null | `null` | ISO 3166-1 alpha-2: the store Apple Music's player opens, over the one in the link |

Processed with nothing set, the tree is `{"catalogs": [], "player": {"theme": "light", "country":
null}}`. A theme other than the two is refused when the container compiles (`The value "blue" is not
allowed for path "omnisong.player.theme". Permissible values: "light", "dark".`), and so is an
empty factory (`The path "omnisong.catalogs.a.factory" cannot contain an empty value, but got
"".`). The options are not validated then - the tree accepts any value - but when the catalogue is
built, the first time it is asked for: a required option missing is an `InvalidConfigException`
naming it.

The player's `compact`, `autoplay` and `color` have no configuration key: the embedder's defaults
are always `compact: false`, `autoplay: false`, `color: null`; a call or a Twig function gives them
([Players](usage-players.md)).

## Services

Each catalogue package the bundle knows - `omnisong/odesli` (`Omnisong\Odesli\OdesliCatalogFactory`)
and `omnisong/itunes` (`Omnisong\Itunes\ItunesCatalogFactory`) - has its factory registered when its
class exists, built on the application's `http_client` service, or with none when the application
has no such service (the factory then makes its own client).

| Service id | Class | Autowiring | |
|---|---|---|---|
| `Omnisong\Registry` | `Registry` | `Registry $registry` | every configured catalogue by name. Public. |
| `Omnisong\Catalog\Catalog` | `Catalog` | `CatalogInterface $catalog` (any argument name not taken by a catalogue), `Catalog $catalog` | the aggregator over every configured catalogue, in the configured order: `OmnisongBundle::aggregate($registry)` |
| `omnisong.catalog.<name>` | `CatalogInterface` | `CatalogInterface $<name>` | one catalogue, built by `Registry::get('<name>')` the first time it is injected |
| `Omnisong\Player\EmbedOptions` | `EmbedOptions` | `EmbedOptions $options` | the player's defaults: the configured theme and country |
| `Omnisong\Player\Embedder` | `Embedder` | `EmbedderInterface $embedder`, `Embedder $embedder` | the embedder, with those defaults |
| `Omnisong\Bridge\Twig\OmnisongExtension` | the Twig extension | - | registered only when Twig is installed, tagged `twig.extension` |
| `Omnisong\Odesli\OdesliCatalogFactory`, `Omnisong\Itunes\ItunesCatalogFactory` | the factories | by their class | tagged `omnisong.catalog_factory` |

```php
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Player\EmbedderInterface;

final class DiscographyController
{
    public function __construct(
        private readonly CatalogInterface $catalog,     // the aggregator
        private readonly CatalogInterface $itunes,      // the "itunes" catalogue alone
        private readonly EmbedderInterface $embedder,
    ) {
    }
}
```

The argument's name selects a catalogue: `CatalogInterface $itunes` is `omnisong.catalog.itunes`
(Symfony's named autowiring aliases, `registerAliasForArgument()`); any other name is the
aggregator. A catalogue's name that is not a valid PHP variable name is still reachable by its
service id or by the registry.

The aggregator is a shared service: its `$incomplete` is that of the last call made through it, in
this request or worker loop. Read it right after the call it describes.

Nothing is built when the container compiles: the aggregator builds every catalogue
(`Registry::all()`) the first time it is injected, a single catalogue the first time it is asked
for.

## An application's own catalogue

A class of the application that implements `Omnisong\Catalog\CatalogFactoryInterface` (usually by
extending `Omnisong\Catalog\CatalogFactory`) is autoconfigured: tagged `omnisong.catalog_factory`,
it joins the registry, and its `getName()` can be named as a `factory`:

```yaml
omnisong:
    catalogs:
        label:
            factory: label_shop     # App\Music\LabelShopCatalogFactory::getName()
        itunes:
            factory: itunes
```

[Writing a catalogue](providers.md) says what such a factory and its catalogue must do.

## Twig

With `twig/twig` installed, `Omnisong\Bridge\Twig\OmnisongExtension` gives the templates:

| Name | Kind | Arguments | Returns |
|---|---|---|---|
| `omnisong_embed` | function, safe HTML | a `PlatformLink`, a URL string, or null; options `{theme, compact, autoplay, country, color}` over the configured defaults | the iframe's HTML, or null when there is no player for it |
| `omnisong_platform` | function | a platform's value (`'apple_music'`) | the `Platform` case, or null |
| `omnisong_platform_label` | filter | a `Platform` or its value | its name (`Apple Music`); what is not a platform is returned as it is |

```twig
{{ omnisong_embed(release.links.get(platform)) }}
{{ omnisong_embed('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy', {theme: 'dark', compact: true}) }}
{{ omnisong_platform('apple_music').label }}        {# Apple Music #}
{{ link.platform.value|omnisong_platform_label }}
{{ 'myspace'|omnisong_platform_label }}             {# myspace #}
```

A URL string is read with `Platform::fromUrl()`; one on no known platform, an empty string and null
give null, so `{% if omnisong_embed(url) %}` is not needed - a null prints nothing. The function's
options are cast (`theme` to a string, `compact` and `autoplay` to booleans); a key not given takes
the configured default.

The bundle registers no command, event, route or controller.

## Caching

The catalogues call remote services that limit the calls (iTunes allows about 20 a minute): cache what they answer, with the application's
cache, keyed by the reference - and never an answer the aggregator marks incomplete:

```php
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

$release = $cache->get('release.'.$upc, function (ItemInterface $item) use ($catalog, $upc, $label) {
    $release = $catalog->release(Reference::upc($upc), $label);
    $item->expiresAfter($catalog->incomplete ? 60 : 86400);   // a half answer is kept one minute only

    return $release;
});
```

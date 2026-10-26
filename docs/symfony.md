# Symfony

Omnisong runs without a framework ([installation](installation.md)); in a Symfony application its
bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel`, and `twig/twig` for the template functions - are not required by
`omnisense/omnisong`: the application has them, and nothing of them is loaded outside Symfony.

Register `Omnisong\Bridge\Symfony\OmnisongBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnisong\Bridge\Symfony\OmnisongBundle::class => ['all' => true],
];
```

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

Every `omnisong/*` package installed registers its factory, on the application's `http_client`.
What is autowired:

| Service | |
|---|---|
| `CatalogInterface` | the aggregator: every configured catalogue, asked in the configured order |
| `CatalogInterface $itunes` | one catalogue by the argument's name (the configured name) |
| `EmbedderInterface` | the embedder, with the site's theme |
| `Registry` | every configured catalogue by name |

```php
public function __construct(CatalogInterface $catalog, EmbedderInterface $embedder) {}
public function __construct(CatalogInterface $itunes) {}   // one catalogue, by its name
```

With Twig installed, `Omnisong\Bridge\Twig\OmnisongExtension` gives the templates the players and
the platforms' names:

```twig
{{ omnisong_embed(release.links.get(platform)) }}
{{ omnisong_embed('https://open.spotify.com/album/...', {theme: 'dark', compact: true}) }}
{{ omnisong_platform('apple_music').label }}   {{ link.platform.value|omnisong_platform_label }}
```

An application's own catalogue - a class implementing `CatalogFactoryInterface` - is registered
too, autoconfigured, and can be named as a `factory`.

Cache what the catalogues answer (they are remote and rate limited) - never an answer the
aggregator marks `incomplete`.

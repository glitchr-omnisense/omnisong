---
title: Writing a catalogue
order: 80
---

# Writing a catalogue

A catalogue is a class that implements `Omnisong\Catalog\CatalogInterface`, built by a factory
that extends `Omnisong\Catalog\CatalogFactory`. It can live in the application (a label's own shop,
a hand-kept discography) or be a package of its own, as `omnisong/itunes` and `omnisong/odesli` are.

## The rules

Every catalogue keeps the contract the aggregator relies on:

- **Answer what it can, refuse the rest.** A kind of reference it cannot read (a UPC for Odesli, a
  Spotify URL for iTunes), or an operation it does not do (`releases()` for Odesli), is a
  `NotSupportedException` - `NotSupportedException::reference($name, $reference)` or
  `::operation($name, 'list an artist\'s releases')` - thrown before any call. The aggregator then
  asks the next catalogue, or the same one again by a reference it learnt.
- **Unknown is not an error.** `links()` gives `PlatformLinks::none()`, `release()` gives `null`,
  `releases()` gives `[]`.
- **Unavailable is not unknown.** The service unreachable, down (5xx), answering something that is
  not JSON, or refusing the key: an `UnavailableException($name, $message, $status, $previous)`.
  Asked to slow down: a `RateLimitedException($name, $retryAfter)`. The aggregator skips the
  catalogue and names it in `$incomplete`, so a site does not cache a half answer.
- **Anything else the service refuses** is a `ProviderException($name, $message, $status)`; the
  aggregator lets it through to the caller.
- **Give ids.** A `PlatformLink`'s `id`, and a `Release`'s `ids` (platform value => id), are what
  the aggregator asks the other catalogues by: a catalogue that gives them lets one that cannot read
  the original reference answer anyway.
- **Keep what was answered.** The aggregator asks `release()` and then `links()` of the same
  reference: both shipped catalogues keep their answers by query for the life of the object, so the
  second call costs nothing.
- **Use the reference's country** over the configured one when it has one.
- **Newest first** for `releases()`, at most `$limit`.

## A catalogue of the application's

A label's shop, which knows what it sells by UPC:

```php
<?php // shop.php

require __DIR__.'/vendor/autoload.php';

use Omnisong\Catalog\Catalog;
use Omnisong\Catalog\CatalogFactory;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Config;
use Omnisong\Exception\NotSupportedException;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Platform;
use Omnisong\Registry;

/** The label's own shop: what it sells, by UPC, from a list the site keeps. */
final class LabelShopCatalog implements CatalogInterface
{
    /** @param array<string, array{title: string, url: string}> $releases by UPC */
    public function __construct(private readonly string $label, private readonly array $releases)
    {
    }

    public function getName(): string
    {
        return 'label_shop';
    }

    public function links(Reference $reference): PlatformLinks
    {
        $release = $this->release($reference);

        return $release?->links ?? PlatformLinks::none();
    }

    public function release(Reference $reference): ?Release
    {
        if (null === $reference->upc) {
            throw NotSupportedException::reference($this->getName(), $reference);   // the next catalogue is asked
        }
        $known = $this->releases[$reference->upc] ?? null;
        if (null === $known) {
            return null;                                                              // unknown: not an error
        }

        return new Release($known['title'], upc: $reference->upc,
            links: new PlatformLinks([new PlatformLink(Platform::SHOP, $known['url'], null, $this->label)]));
    }

    public function releases(string $artist, int $limit = 50, ?string $country = null): array
    {
        throw NotSupportedException::operation($this->getName(), 'list an artist\'s releases');
    }
}

final class LabelShopCatalogFactory extends CatalogFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnisong.factory_name' => 'label_shop',
            'omnisong.required_options' => ['label'],
            'releases' => [],
        ]);
    }

    protected function build(Config $c): CatalogInterface
    {
        return new LabelShopCatalog((string) $c['label'], (array) $c['releases']);
    }
}

$registry = new Registry([new LabelShopCatalogFactory()], [
    'shop' => ['factory' => 'label_shop', 'options' => [
        'label' => 'ES-DUR',
        'releases' => ['4015372820954' => ['title' => 'Perspectives Concertantes', 'url' => 'https://www.es-dur.de/shop/perspectives']],
    ]],
]);
$catalog = new Catalog($registry->all());

$release = $catalog->release(Reference::upc('4015372820954'));
echo $release->title, "\n";
foreach ($release->links as $link) {
    echo '  ', $link->platform->value, ' ', $link->title(), ' ', $link->url, "\n";
}
var_dump($catalog->release(Reference::upc('0000000000000')));
var_dump(count($catalog->links(Reference::parse('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy'))));

try {
    (new LabelShopCatalogFactory())->create([]);
} catch (\Omnisong\Exception\InvalidConfigException $e) {
    echo $e->getMessage(), "\n";
}
```

```
$ php shop.php
Perspectives Concertantes
  shop ES-DUR https://www.es-dur.de/shop/perspectives
NULL
int(0)
The "label_shop" catalogue needs: label.
```

(run on 2026-10-26, PHP 8.4, no network). It reads UPCs only, so a Spotify URL gives no link - not
an error: the aggregator caught the `NotSupportedException` and had no other catalogue to ask.
Put it first in the registry, before iTunes and Odesli, and its `SHOP` link joins theirs; a
`Label` given to the call still replaces it.

In Symfony, the factory is autoconfigured (tagged `omnisong.catalog_factory`) as soon as it is a
service, and named in `omnisong.catalogs.<name>.factory` ([Symfony](symfony.md#an-applications-own-catalogue)).

## The factory

`populate(Config $c)` sets, with `$c->defaults([...])`:

| Key | |
|---|---|
| `omnisong.factory_name` | the factory's name, what `factory:` names: `itunes`, `label_shop` |
| `omnisong.required_options` | the options `create()` refuses to go without (`InvalidConfigException`, naming them) |
| every other option | its default |

`build(Config $c)` makes the catalogue from the filled config. A catalogue that calls a service
takes `$this->http`, the client the factory was given, or makes its own when it is null
(`$this->http ?? HttpClient::create()`, which needs `symfony/http-client`). Nothing is called while
building: the first call is made by the first question.

## A package of its own

The shipped catalogues' layout, to copy:

- name `omnisong/<slug>`, namespace `Omnisong\<Name>\`, PSR-4 from the package root, `Tests/`
  excluded from the class map;
- `require`: `php` `>=8.2`, `omnisense/omnisong` `^1.0@dev`, and `symfony/http-client`
  (`^6.4|^7.0|^8.0`) when it calls a service;
- the catalogue's base URL as a `BASE_URI` constant, overridable by a `base_uri` option (a test
  server, a proxy);
- tests on recorded answers (`Tests/Fixtures/*.json`) through a `MockHttpClient`: every
  failure of the table in [Errors](errors.md#by-catalogue);
- a README with a "Responsibility" section naming the service's terms.

For the bundle to register the factory without the application declaring it, its class must be in
`OmnisongBundle::FACTORIES`; for the harness to install it, in `docker/harness/plugins.php` (slug,
tests' namespace, factory class) and `docker/harness/config/catalogs.php` (its options from the
environment).

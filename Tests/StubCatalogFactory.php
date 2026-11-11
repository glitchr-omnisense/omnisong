<?php

namespace Omnisong\Tests;

use Omnisong\Catalog\CatalogFactory;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Config;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Platform;

/** An application's own catalogue, for the tests: it knows one link, on the platform it is told. */
final class StubCatalogFactory extends CatalogFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnisong.factory_name' => 'stub',
            'omnisong.required_options' => ['name'],
            'platform' => 'other',
        ]);
    }

    protected function build(Config $c): CatalogInterface
    {
        $name = (string) $c->string('name');
        $platform = Platform::from((string) $c->string('platform'));
        $links = static fn (Reference $r) => new PlatformLinks([new PlatformLink($platform, 'https://'.$name.'.example/'.$r)]);

        return new StubCatalog($name, $links, static fn (Reference $r) => new Release('From '.$name, links: $links($r)));
    }
}

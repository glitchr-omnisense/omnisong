---
title: Laravel
order: 16
---

# Laravel

omnibridge/laravel, the bridge that hosts omnibase in Laravel, has no Omnisong provider, and there
is no other Laravel package for it. The registry, the aggregator and the embedder are plain PHP: a
service provider of the application builds them once, from a config file, with a
`symfony/http-client` client (both catalogue packages require it; Laravel's own HTTP client is
Guzzle's, which the catalogues do not take).

```php
// config/omnisong.php
return [
    'catalogs' => [                      // in the order the aggregator asks them
        'odesli' => ['factory' => 'odesli', 'options' => [
            'api_key' => env('ODESLI_API_KEY'),
            'country' => 'DE',
        ]],
        'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de']],
    ],
    'player' => ['theme' => 'light', 'country' => null],
];
```

```php
// app/Providers/OmnisongServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Omnisong\Catalog\Catalog;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Odesli\OdesliCatalogFactory;
use Omnisong\Player\Embedder;
use Omnisong\Player\EmbedderInterface;
use Omnisong\Player\EmbedOptions;
use Omnisong\Registry;
use Symfony\Component\HttpClient\HttpClient;

final class OmnisongServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function (): Registry {
            $http = HttpClient::create();

            return new Registry([
                new OdesliCatalogFactory($http),
                new ItunesCatalogFactory($http),
            ], config('omnisong.catalogs'));
        });

        $this->app->singleton(CatalogInterface::class, fn ($app) => new Catalog($app->make(Registry::class)->all()));

        $this->app->singleton(EmbedderInterface::class, fn () => new Embedder(new EmbedOptions(
            config('omnisong.player.theme', EmbedOptions::LIGHT),
            country: config('omnisong.player.country'),
        )));
    }
}
```

Register the provider (`bootstrap/providers.php` on Laravel 11 and later), then inject what is
needed:

```php
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Model\Reference;
use Omnisong\Player\EmbedderInterface;

final class DiscographyController
{
    public function show(string $upc, CatalogInterface $catalog, EmbedderInterface $embedder)
    {
        $release = $catalog->release(Reference::upc($upc));
        // ...
    }
}
```

Differences from the Symfony bundle:

- list in the provider every catalogue factory the application uses: a hand-built registry knows
  only the factories it is given;
- there is no Blade counterpart of the Twig functions: call `$embedder->embed($link)?->html` in the
  controller or a view component, and print it unescaped (`{!! $html !!}`) - the embedder escapes
  the URL and the title it puts in the iframe;
- the theme is not validated: a value other than `dark` is the light theme.

Configuration caching (`php artisan config:cache`) keeps working: the options are plain arrays read
through `config()`. This page was written from the registry's and the embedder's API; it was not run
in a Laravel application.

---
title: Testing
order: 60
---

# Testing

An application that uses Omnisong tests its own code, not the services: the catalogues take the
HTTP client they call with, so a test hands them a `MockHttpClient` and the answers it wants; and
the aggregator takes any `CatalogInterface`, so a test can give it catalogues of its own. The
embedder calls nothing and needs no double.

Omnisong ships no fake catalogue and no conformance suite: there is no `Testing\` namespace in the
package. The core's own stubs (`Omnisong\Tests\StubCatalog`, `StubCatalogFactory`) are in its
`Tests/` directory, autoloaded for its development only.

## Two ways, in one test case

```php
use Omnisong\Catalog\Catalog;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Exception\UnavailableException;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Model\Label;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Model\Track;
use Omnisong\Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscographyTest extends TestCase
{
    public function testTheReleaseComesFromTheRecordedAnswer(): void
    {
        $answer = new MockResponse(json_encode(['resultCount' => 2, 'results' => [
                ['wrapperType' => 'collection', 'collectionId' => 42, 'collectionName' => 'Harp - EP', 'artistName' => 'Anaëlle Tourret',
                 'collectionViewUrl' => 'https://music.apple.com/de/album/harp/42?uo=4', 'copyright' => '℗ 2025 ES-DUR', 'releaseDate' => '2025-02-28T08:00:00Z'],
                ['wrapperType' => 'track', 'kind' => 'song', 'collectionId' => 42, 'trackId' => 43, 'trackName' => 'Allegro', 'trackNumber' => 1,
                 'discNumber' => 1, 'trackTimeMillis' => 677000, 'previewUrl' => 'https://audio.example/43.m4a'],
            ]]));
        $http = new MockHttpClient([$answer]);
        $itunes = (new ItunesCatalogFactory($http))->create(['country' => 'de']);

        $release = $itunes->release(Reference::upc('4015372820954'));

        self::assertSame('Harp', $release->title);
        self::assertSame(Release::EP, $release->type);
        self::assertSame('ES-DUR', $release->label->name);
        self::assertSame(677, $release->tracks[0]->duration);
        self::assertSame('https://music.apple.com/de/album/harp/42', $release->links->get(Platform::APPLE_MUSIC)->url);
        self::assertSame('https://itunes.apple.com/lookup?upc=4015372820954&entity=song&country=de&limit=200', $answer->getRequestUrl());
    }

    public function testADownCatalogueIsSkippedAndSaidSo(): void
    {
        $down = new class implements CatalogInterface {
            public function getName(): string { return 'down'; }
            public function links(Reference $reference): PlatformLinks { throw new UnavailableException('down', 'HTTP 503', 503); }
            public function release(Reference $reference): ?Release { throw new UnavailableException('down', 'HTTP 503', 503); }
            public function releases(string $artist, int $limit = 50, ?string $country = null): array { return []; }
        };
        $known = new class implements CatalogInterface {
            public function getName(): string { return 'known'; }
            public function links(Reference $reference): PlatformLinks { return new PlatformLinks([new PlatformLink(Platform::DEEZER, 'https://www.deezer.com/album/302127')]); }
            public function release(Reference $reference): ?Release { return new Release('Harp', tracks: [new Track('Allegro', 1)]); }
            public function releases(string $artist, int $limit = 50, ?string $country = null): array { return []; }
        };
        $catalog = new Catalog([$down, $known]);

        $release = $catalog->release(Reference::upc('4015372820954'), new Label('ES-DUR', 'https://www.es-dur.de'));

        self::assertSame(['down'], $catalog->incomplete);
        self::assertSame(['label', 'deezer'], array_keys($release->links->toArray()));
    }
}
```

```
$ vendor/bin/phpunit --bootstrap vendor/autoload.php DiscographyTest.php
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.27

..                                                                  2 / 2 (100%)

Time: 00:00.017, Memory: 8.00 MB

OK (2 tests, 8 assertions)
```

(run on 2026-10-26 on the packages' checkouts, no network)

- The first test gives iTunes one recorded answer and checks what the application relies on: the
  store's " - EP" read as the type, the label from the `℗` line, the duration in seconds, the view
  URL without its `uo` tracking parameter, and the request sent (`MockResponse::getRequestUrl()`).
  iTunes answers with a `text/javascript` content type; the catalogue reads the body as JSON
  whatever the header says, so a `MockResponse` needs no header.
- The second gives the aggregator two catalogues of its own: one that is down, one that knows the
  release. It checks that the half answer is said (`$incomplete`) and that the label goes first.

## Recorded answers

The packages' own tests keep real answers as fixtures; an application can reuse their shape:

| File | What |
|---|---|
| `omnisong/itunes`: `Tests/Fixtures/lookup-upc.json` | `lookup?upc=4015372820954&entity=song`: the album and its 8 tracks (store `de`, 2026-10-03) |
| `omnisong/itunes`: `Tests/Fixtures/lookup-track.json` | a lookup that answers with one track (an ISRC, a track's id) |
| `omnisong/itunes`: `Tests/Fixtures/search-albums.json` | `search?entity=album&attribute=artistTerm` |
| `omnisong/odesli`: `Tests/Fixtures/links-album.json` | `links` for the same album, written from what album.link showed |
| core: `docker/harness/recorded/*.json` | the same iTunes and Odesli answers, for the harness's `bare --recorded` |

A `MockHttpClient` built with a callable can serve them by URL, as the harness's `bare` script does:

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$http = new MockHttpClient(static function (string $method, string $url): MockResponse {
    return match (true) {
        str_contains($url, 'itunes.apple.com/lookup') => new MockResponse(file_get_contents(__DIR__.'/fixtures/itunes-lookup.json')),
        str_contains($url, 'api.song.link') => new MockResponse(file_get_contents(__DIR__.'/fixtures/odesli-links.json')),
        default => new MockResponse('{"resultCount":0,"results":[]}'),
    };
});
```

Failures are answers too: `new MockResponse('', ['http_code' => 503])` is an
`UnavailableException`, `['http_code' => 429, 'response_headers' => ['Retry-After' => '30']]` a
`RateLimitedException` with `retryAfter` 30, and a 401 from song.link the "key refused" case.

## In Symfony

The bundle builds the factories on the `http_client` service: in the test environment, replace it
with a `MockHttpClient` and every catalogue calls it.

```yaml
# config/packages/test/framework.yaml
framework:
    http_client:
        mock_response_factory: App\Tests\MusicResponses   # an invokable class returning MockResponse
```

Or replace one catalogue: `omnisong.catalog.<name>` is a service like another, and
`static::getContainer()->set('omnisong.catalog.itunes', $stub)` works in a `KernelTestCase` before
it is first used. The aggregator (`Omnisong\Catalog\Catalog`) is built from the registry, not from
those services: to replace what it asks, replace `http_client` or the registry.

## The package's own tests

`docker compose run --rm omnisong test` runs every package's tests in the [harness](harness.md);
`Tests/BareTest.php` checks in a process of its own that no class of a framework is loaded in plain
PHP, and `Tests/Bridge/OmnisongBundleTest.php` boots a kernel with the bundle and checks the
configured order, the named catalogues, the embedder's theme and the Twig functions.

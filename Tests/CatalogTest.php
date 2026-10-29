<?php

namespace Omnisong\Tests;

use Omnisong\Catalog\Catalog;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UnavailableException;
use Omnisong\Model\Label;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Model\Track;
use Omnisong\Platform;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    private const UPC = '4015372820954';
    private const ITUNES_ID = '1793146044';

    protected function setUp(): void
    {
        StubCatalog::$log = [];
    }

    /** iTunes as it is: a UPC or its own id gives the release, its tracks and previews, and the Apple links. */
    private static function itunes(): StubCatalog
    {
        $release = new Release('Perspectives Concertantes', 'Anaelle Tourret', new Label('C2 Hamburg'), null, coverUrl: 'https://is1-ssl.mzstatic.com/cover/1200x1200bb.jpg',
            tracks: [new Track('I. Allegro Moderato', 1, previewUrl: 'https://audio-ssl.itunes.apple.com/1.m4a'), new Track('II. Tema con Variazioni', 2, previewUrl: 'https://audio-ssl.itunes.apple.com/2.m4a')],
            links: new PlatformLinks([new PlatformLink(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/perspectives-concertantes/1793146044', self::ITUNES_ID)]),
            ids: ['itunes' => self::ITUNES_ID, 'apple_music' => self::ITUNES_ID]);
        $find = static fn (Reference $r) => self::UPC === $r->upc || self::ITUNES_ID === $r->id ? $release : null;

        return new StubCatalog('itunes', static fn (Reference $r) => $find($r)?->links ?? PlatformLinks::none(), $find);
    }

    /** Odesli as it is: no UPC, no tracks; asked by the iTunes id, the album on every platform. */
    private static function odesli(): StubCatalog
    {
        $links = static fn (Reference $r) => null === $r->url && !\in_array($r->platform, [Platform::ITUNES, Platform::APPLE_MUSIC], true)
            ? throw \Omnisong\Exception\NotSupportedException::reference('odesli', $r)
            : new PlatformLinks([
                new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy'),
                new PlatformLink(Platform::APPLE_MUSIC, 'https://geo.music.apple.com/de/album/_/1793146044'),
                new PlatformLink(Platform::TIDAL, 'https://listen.tidal.com/album/414051173'),
            ]);

        return new StubCatalog('odesli', $links, static fn (Reference $r) => new Release('Perspectives Concertantes', coverUrl: 'https://odesli.example/512.jpg', links: $links($r)));
    }

    public function testEachCatalogueGivesWhatItKnowsAndTheLabelGoesFirst(): void
    {
        $odesli = self::odesli();
        $itunes = self::itunes();
        $catalog = new Catalog([$odesli, $itunes]);
        $release = $catalog->release(Reference::upc(self::UPC), new Label('ES-DUR', 'https://www.es-dur.de'));

        self::assertSame('Perspectives Concertantes', $release->title);
        self::assertSame('ES-DUR', $release->label->name, 'the label the site knows over the one the store prints');
        self::assertCount(2, $release->tracks);
        self::assertSame('https://audio-ssl.itunes.apple.com/1.m4a', $release->tracks[0]->previewUrl);
        self::assertSame(['label', 'spotify', 'apple_music', 'tidal'], array_keys($release->links->toArray()));
        self::assertSame('ES-DUR', $release->links->first()->title());
        self::assertSame('https://music.apple.com/de/album/perspectives-concertantes/1793146044', $release->links->get(Platform::APPLE_MUSIC)->url, 'the release\'s own link before the other catalogues\'');
        self::assertSame([], $catalog->incomplete);
        self::assertSame(['odesli', 'itunes'], $catalog->names());

        self::assertSame([
            'odesli release '.self::UPC,            // not supported: skipped
            'itunes release '.self::UPC,
            'odesli links '.self::UPC,              // the links: Odesli again, by what iTunes found
            'odesli links itunes:'.self::ITUNES_ID,
            'itunes links '.self::UPC,
        ], StubCatalog::$log);
    }

    public function testAUpcLearntIsWhatTheNextCatalogueIsAsked(): void
    {
        $finder = new StubCatalog('finder', null, static fn () => new Release('Perspectives Concertantes', upc: self::UPC));
        $itunes = self::itunes();
        $catalog = new Catalog([$finder, $itunes]);
        $release = $catalog->release(Reference::url('https://www.es-dur.de/perspectives'));

        self::assertSame(self::UPC, $itunes->asked[0]->upc, 'the UPC the first one found');
        self::assertSame(self::UPC, $release->upc);
        self::assertCount(2, $release->tracks);
        self::assertSame('C2 Hamburg', $release->label->name, 'without a label from the site: the store\'s');
        self::assertFalse($release->links->has(Platform::LABEL), 'a label without an address has no link');
    }

    public function testACatalogueThatIsDownIsSkippedAndSaidSo(): void
    {
        $down = new StubCatalog('odesli', static fn () => throw new UnavailableException('odesli', 'HTTP 401'), static fn () => throw new RateLimitedException('odesli', 60));
        $catalog = new Catalog([$down, self::itunes()]);
        $release = $catalog->release(Reference::upc(self::UPC), new Label('ES-DUR', 'https://www.es-dur.de'));

        self::assertCount(2, $release->tracks, 'the answer of those that could');
        self::assertSame(['label', 'apple_music'], array_keys($release->links->toArray()));
        self::assertSame(['odesli'], $catalog->incomplete, 'once, however often it was asked: not an answer to cache');

        $catalog->releases('Anaëlle Tourret');
        self::assertSame([], $catalog->incomplete, 'each call says for itself');
    }

    public function testLinksAloneAreEveryCataloguesMerged(): void
    {
        $catalog = new Catalog([self::odesli(), self::itunes()]);
        $links = $catalog->links(Reference::url('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy'), new Label('ES-DUR', 'https://www.es-dur.de', 'https://www.es-dur.de/shop'));

        self::assertSame(['label', 'shop', 'spotify', 'apple_music', 'tidal'], array_keys($links->toArray()));
        self::assertCount(0, $catalog->links(Reference::isrc('DEAR42500001')), 'nobody knows it: none');

        StubCatalog::$log = [];
        $links = $catalog->links(Reference::upc(self::UPC));
        self::assertSame(['spotify', 'apple_music', 'tidal'], array_keys($links->toArray()), 'Odesli reached by the id iTunes found');
        self::assertSame('https://music.apple.com/de/album/perspectives-concertantes/1793146044', $links->get(Platform::APPLE_MUSIC)->url, 'the first catalogue that answered keeps its link');
        self::assertSame(['odesli links '.self::UPC, 'itunes links '.self::UPC, 'odesli links apple_music:'.self::ITUNES_ID], StubCatalog::$log);
    }

    /** iTunes answers an ISRC with its album's links: the id learnt is the album's, and Odesli is asked for an album. */
    public function testAnIdLearntFromAnIsrcsLinksIsAskedAsTheAlbumItNames(): void
    {
        $itunes = new StubCatalog('itunes', static fn (Reference $r) => new PlatformLinks([new PlatformLink(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/perspectives-concertantes/1793146044', self::ITUNES_ID)]));
        $odesli = self::odesli();
        $links = (new Catalog([$odesli, $itunes]))->links(Reference::isrc('DEAR42500001'));

        self::assertSame(['spotify', 'apple_music', 'tidal'], array_keys($links->toArray()));
        $learnt = end($odesli->asked);
        self::assertSame(self::ITUNES_ID, $learnt->id);
        self::assertSame(Reference::ALBUM, $learnt->type, 'the album\'s id, asked as an album: Odesli would look a song up by it');
    }

    public function testUnknownEverywhereIsNull(): void
    {
        $catalog = new Catalog([self::itunes()]);

        self::assertNull($catalog->release(Reference::upc('4000000000001')));
        self::assertNull((new Catalog([]))->release(Reference::upc(self::UPC)));
    }

    public function testAnArtistsReleasesAreTheFirstCatalogueThatHasSome(): void
    {
        $none = new StubCatalog('none', releases: static fn () => []);
        $down = new StubCatalog('down', releases: static fn () => throw new UnavailableException('down', 'HTTP 503'));
        $some = new StubCatalog('some', releases: static fn (string $artist, int $limit, ?string $country) => [new Release($artist.' '.$limit.' '.$country)]);
        $catalog = new Catalog(new \ArrayIterator([self::odesli(), $none, $down, $some]));

        self::assertSame('Brieuc Vourch 10 FR', $catalog->releases('Brieuc Vourch', 10, 'FR')[0]->title);
        self::assertSame(['down'], $catalog->incomplete);
        self::assertSame([], (new Catalog([$none]))->releases('Nobody'));
    }
}

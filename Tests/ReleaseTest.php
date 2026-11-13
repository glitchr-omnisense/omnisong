<?php

namespace Omnisong\Tests;

use Omnisong\Model\Label;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Release;
use Omnisong\Model\Track;
use Omnisong\Platform;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase
{
    public function testAMergeFillsWhatWasNotKnown(): void
    {
        $ours = new Release('Symphonies Nos. 5 & 7', 'Carlos Kleiber', links: new PlatformLinks([new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/album/a')]), ids: ['spotify' => 'a']);
        $theirs = new Release(
            'Beethoven: Symphonies Nos. 5 & 7', 'Wiener Philharmoniker & Carlos Kleiber', new Label('Label Exemple'), '028948637751', new \DateTimeImmutable('2025-02-28'), 'https://img.example/cover.jpg', Release::ALBUM,
            [new Track('I. Allegro', 1)],
            new PlatformLinks([new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/album/b'), new PlatformLink(Platform::APPLE_MUSIC, 'https://music.apple.com/de/album/x/1')]),
            'Klassik', 8, ['spotify' => 'b', 'itunes' => '1'], '℗ 1995 Label Exemple',
        );
        $merged = $ours->merge($theirs);

        self::assertSame('Symphonies Nos. 5 & 7', $merged->title, 'ours stays');
        self::assertSame('Carlos Kleiber', $merged->artist);
        self::assertSame('Label Exemple', $merged->label?->name);
        self::assertSame('028948637751', $merged->upc);
        self::assertSame('2025-02-28', $merged->releasedAt?->format('Y-m-d'));
        self::assertSame('https://img.example/cover.jpg', $merged->coverUrl);
        self::assertSame('Klassik', $merged->genre);
        self::assertSame(8, $merged->trackCount);
        self::assertSame('℗ 1995 Label Exemple', $merged->copyright);
        self::assertSame(['spotify' => 'a', 'itunes' => '1'], $merged->ids);
        self::assertCount(1, $merged->tracks, 'the tracks of the one that has some');
        self::assertSame(['spotify' => 'https://open.spotify.com/album/a', 'apple_music' => 'https://music.apple.com/de/album/x/1'], $merged->links?->toArray());
        self::assertSame($ours, $ours->merge(null));
        self::assertNull($ours->upc, 'immutable');
    }

    public function testPreviewsAreMatchedByIsrcThenByPlace(): void
    {
        $ours = new Release('Album', tracks: [
            new Track('One', 1, isrc: 'QZZZZ2500001'),
            new Track('Two', 2, isrc: 'QZZZZ2500002'),
            new Track('Three', 3),
            new Track('Four', 4, isrc: 'QZZZZ2500004', previewUrl: 'https://site.example/four.mp3'),
        ]);
        $theirs = new Release('Album', tracks: [
            new Track('2', 1, isrc: 'QZZZZ2500002', previewUrl: 'https://p.example/2.m4a'),
            new Track('1', 2, isrc: 'QZZZZ2500001', previewUrl: 'https://p.example/1.m4a'),
            new Track('3', 3, previewUrl: 'https://p.example/3.m4a'),
            new Track('4', 4, isrc: 'QZZZZ2500004', previewUrl: 'https://p.example/4.m4a'),
        ]);
        $tracks = $ours->merge($theirs)->tracks;

        self::assertSame(['One', 'Two', 'Three', 'Four'], array_map(static fn (Track $t) => $t->title, $tracks), 'our tracks, our titles');
        self::assertSame('https://p.example/1.m4a', $tracks[0]->previewUrl, 'by ISRC, wherever it sits');
        self::assertSame('https://p.example/2.m4a', $tracks[1]->previewUrl);
        self::assertSame('https://p.example/3.m4a', $tracks[2]->previewUrl, 'without an ISRC: the track at the same place');
        self::assertSame('https://site.example/four.mp3', $tracks[3]->previewUrl, 'a preview already there is kept');
    }

    public function testWithersGiveANewRelease(): void
    {
        $release = new Release('Album');
        $with = $release->withTracks([new Track('One', 1), new Track('Two', 2)])->withLabel(new Label('Label Exemple'))->withLinks(PlatformLinks::none());

        self::assertSame(2, $with->trackCount);
        self::assertSame('Label Exemple', $with->label?->name);
        self::assertNotNull($with->links);
        self::assertCount(0, $with->links);
        self::assertNull($release->links);
        self::assertSame([], $release->tracks);
    }
}

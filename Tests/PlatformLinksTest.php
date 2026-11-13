<?php

namespace Omnisong\Tests;

use Omnisong\Model\Label;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformLinksTest extends TestCase
{
    private static function links(): PlatformLinks
    {
        return new PlatformLinks([
            new PlatformLink(Platform::ITUNES, 'https://music.apple.com/de/album/x/1?app=itunes'),
            new PlatformLink(Platform::DEEZER, 'https://www.deezer.com/album/1'),
            new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/album/a'),
            new PlatformLink(Platform::QOBUZ, 'https://open.qobuz.com/album/a'),
        ]);
    }

    public function testTheyComeInTheOrderASiteShowsThem(): void
    {
        $links = self::links();

        self::assertSame(['spotify', 'deezer', 'qobuz', 'itunes'], array_keys(iterator_to_array($links)));
        self::assertSame(Platform::SPOTIFY, $links->first()?->platform);
        self::assertCount(4, $links);
        self::assertTrue($links->has(Platform::QOBUZ));
        self::assertNull($links->get(Platform::TIDAL));
        self::assertSame([Platform::SPOTIFY, Platform::DEEZER], array_map(static fn (PlatformLink $l) => $l->platform, $links->embeddable()));
        self::assertNull(PlatformLinks::none()->first());
    }

    public function testTheLabelGoesFirstAndItsShopNext(): void
    {
        $links = self::links()->withLabel(new Label('Label Exemple', 'https://label.example.org', 'https://label.example.org/shop'));

        self::assertSame(['label', 'shop', 'spotify', 'deezer', 'qobuz', 'itunes'], array_keys($links->toArray()));
        self::assertSame('Label Exemple', $links->first()?->title());
        self::assertSame('https://label.example.org', $links->first()->url);
        self::assertSame('https://label.example.org/shop', $links->get(Platform::SHOP)?->url);
        self::assertSame('Label Exemple', $links->get(Platform::SHOP)->title());
        self::assertSame('Spotify', $links->get(Platform::SPOTIFY)?->title());

        $shopOnly = self::links()->withLabel(new Label('Label Exemple', null, 'https://label.example.org/shop'));
        self::assertSame('https://label.example.org/shop', $shopOnly->first()?->url, 'a label known by its shop alone');
        self::assertFalse($shopOnly->has(Platform::SHOP), 'not twice');

        self::assertSame(Platform::SPOTIFY, self::links()->withLabel(new Label('Label Exemple'))->first()?->platform, 'a label without an address has no link');
        self::assertCount(4, self::links(), 'immutable');
    }

    public function testAMergeKeepsWhatWasThere(): void
    {
        $merged = self::links()->merge(new PlatformLinks([
            new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/album/other'),
            new PlatformLink(Platform::TIDAL, 'https://listen.tidal.com/album/1'),
        ]));

        self::assertSame('https://open.spotify.com/album/a', $merged->get(Platform::SPOTIFY)?->url, 'ours first');
        self::assertSame(['spotify', 'deezer', 'qobuz', 'tidal', 'itunes'], array_keys($merged->toArray()));
        self::assertSame(self::links()->toArray(), self::links()->merge(null)->toArray());

        $replaced = self::links()->with(new PlatformLink(Platform::SPOTIFY, 'https://open.spotify.com/album/new'));
        self::assertSame('https://open.spotify.com/album/new', $replaced->get(Platform::SPOTIFY)?->url, 'with() puts its link over the one there');
    }

    public function testTheyAreStoredAsUrlsByPlatform(): void
    {
        $stored = self::links()->withLabel(new Label('Label Exemple', 'https://label.example.org'))->toArray();
        self::assertSame('https://label.example.org', $stored['label']);

        $links = PlatformLinks::fromArray($stored + ['myspace' => 'https://myspace.com/x', 'tidal' => ''], 'Label Exemple');
        self::assertSame($stored, $links->toArray(), 'what is not a platform, or is empty, is left out');
        self::assertSame('Label Exemple', $links->first()?->title());
        self::assertSame('Deezer', $links->get(Platform::DEEZER)?->title());
    }
}

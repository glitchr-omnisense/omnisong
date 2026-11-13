<?php

namespace Omnisong\Tests;

use Omnisong\Model\Reference;
use Omnisong\Platform;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase
{
    /** What parse() cannot read is an OmnisongException, as every exception Omnisong throws (and still an \InvalidArgumentException). */
    public function testAnUnreadableValueIsAnOmnisongException(): void
    {
        try {
            Reference::parse('neither a link nor a code');
            self::fail('parsed');
        } catch (\Omnisong\Exception\OmnisongException $e) {
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
        }
    }

    public function testAUrlIsRead(): void
    {
        $reference = Reference::parse(' https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy ', 'DE');

        self::assertSame('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy', $reference->url);
        self::assertSame(Platform::SPOTIFY, $reference->platform);
        self::assertNull($reference->upc);
        self::assertNull($reference->isrc);
        self::assertSame('DE', $reference->country);
        self::assertSame('https://open.spotify.com/album/4aawyAB9vmqN3uQ7FjRGTy', (string) $reference);
        self::assertNull(Reference::parse('https://label.example.org/album')->platform, 'a URL of nobody we know is still a URL');
    }

    public function testAUpcOrAnEanIsARelease(): void
    {
        foreach (['0028948637751', '724385583629', '00028948637751'] as $upc) {
            $reference = Reference::parse($upc);
            self::assertSame($upc, $reference->upc);
            self::assertSame(Reference::ALBUM, $reference->type);
            self::assertNull($reference->url);
        }
        self::assertSame('028948637751', Reference::upc('0 28948 63775 1')->upc, 'as printed under the barcode');
    }

    public function testAnIsrcIsARecording(): void
    {
        foreach (['QZZZZ2500001', 'qz-zzz-25-00001', 'QZ-ZZZ-25-00001'] as $isrc) {
            $reference = Reference::parse($isrc);
            self::assertSame('QZZZZ2500001', $reference->isrc);
            self::assertSame(Reference::SONG, $reference->type);
            self::assertNull($reference->upc);
        }
    }

    public function testAPlatformsIdAndItsCountry(): void
    {
        $reference = Reference::id(Platform::ITUNES, '1644892939');

        self::assertSame('itunes:1644892939', (string) $reference);
        self::assertSame(Reference::ALBUM, $reference->type);
        self::assertSame('FR', $reference->withCountry('FR')->country);
        self::assertSame('1644892939', $reference->withCountry('FR')->id);
        self::assertNull($reference->country);
    }

    public function testAnythingElseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Reference::parse('Symphonies Nos. 5 & 7');
    }
}

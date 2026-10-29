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
        self::assertNull(Reference::parse('https://www.es-dur.de/album')->platform, 'a URL of nobody we know is still a URL');
    }

    public function testAUpcOrAnEanIsARelease(): void
    {
        foreach (['4015372820954', '724385583629', '04015372820954'] as $upc) {
            $reference = Reference::parse($upc);
            self::assertSame($upc, $reference->upc);
            self::assertSame(Reference::ALBUM, $reference->type);
            self::assertNull($reference->url);
        }
        self::assertSame('4015372820954', Reference::upc('4 015372 820954')->upc, 'as printed under the barcode');
    }

    public function testAnIsrcIsARecording(): void
    {
        foreach (['DEAR42500001', 'de-ar4-25-00001', 'DE-AR4-25-00001'] as $isrc) {
            $reference = Reference::parse($isrc);
            self::assertSame('DEAR42500001', $reference->isrc);
            self::assertSame(Reference::SONG, $reference->type);
            self::assertNull($reference->upc);
        }
    }

    public function testAPlatformsIdAndItsCountry(): void
    {
        $reference = Reference::id(Platform::ITUNES, '1793146044');

        self::assertSame('itunes:1793146044', (string) $reference);
        self::assertSame(Reference::ALBUM, $reference->type);
        self::assertSame('FR', $reference->withCountry('FR')->country);
        self::assertSame('1793146044', $reference->withCountry('FR')->id);
        self::assertNull($reference->country);
    }

    public function testAnythingElseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Reference::parse('Perspectives concertantes');
    }
}

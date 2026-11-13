<?php

namespace Omnisong\Tests;

use Omnisong\Catalog\CatalogInterface;
use Omnisong\Exception\NotSupportedException;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;

/**
 * A catalogue that answers with what the test gives it (a closure of the
 * reference per operation; none: not supported) and remembers what it was asked.
 */
final class StubCatalog implements CatalogInterface
{
    /** @var list<string> "links spotify:abc", "release 028948637751"... across every stub, in the order asked */
    public static array $log = [];

    /** @var list<Reference> */
    public array $asked = [];

    /**
     * @param (\Closure(Reference): PlatformLinks)|null                     $links
     * @param (\Closure(Reference): ?Release)|null                          $release
     * @param (\Closure(string, int, ?string): list<Release>)|null          $releases
     */
    public function __construct(
        private readonly string $name,
        private readonly ?\Closure $links = null,
        private readonly ?\Closure $release = null,
        private readonly ?\Closure $releases = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function links(Reference $reference): PlatformLinks
    {
        self::$log[] = \sprintf('%s links %s', $this->name, $reference);
        $this->asked[] = $reference;

        return $this->links ? ($this->links)($reference) : throw NotSupportedException::reference($this->name, $reference);
    }

    public function release(Reference $reference): ?Release
    {
        self::$log[] = \sprintf('%s release %s', $this->name, $reference);
        $this->asked[] = $reference;

        return $this->release ? ($this->release)($reference) : throw NotSupportedException::reference($this->name, $reference);
    }

    public function releases(string $artist, int $limit = 50, ?string $country = null): array
    {
        self::$log[] = \sprintf('%s releases %s', $this->name, $artist);

        return $this->releases ? ($this->releases)($artist, $limit, $country) : throw NotSupportedException::operation($this->name, 'list an artist\'s releases');
    }
}

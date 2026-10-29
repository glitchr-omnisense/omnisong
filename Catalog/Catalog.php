<?php

namespace Omnisong\Catalog;

use Omnisong\Exception\NotSupportedException;
use Omnisong\Exception\UnavailableException;
use Omnisong\Model\Label;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Platform;

/**
 * Every configured catalogue asked in turn, their answers merged: Odesli
 * gives the links, iTunes the tracks and their previews, and the label
 * the site knows goes first. What one catalogue cannot do the next does;
 * a catalogue that is down is skipped, and remembered as skipped so the
 * caller does not cache a half answer (see $incomplete).
 */
final class Catalog implements CatalogInterface
{
    /** @var list<CatalogInterface> */
    private array $catalogs;

    /** @var list<string> the catalogues that could not be reached during the last call */
    public array $incomplete = [];

    /** @param iterable<CatalogInterface> $catalogs in the order they are asked */
    public function __construct(iterable $catalogs)
    {
        $this->catalogs = \is_array($catalogs) ? array_values($catalogs) : iterator_to_array($catalogs, false);
    }

    public function getName(): string
    {
        return 'catalog';
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (CatalogInterface $c) => $c->getName(), $this->catalogs);
    }

    public function links(Reference $reference, ?Label $label = null): PlatformLinks
    {
        $this->incomplete = [];
        $links = $this->gather([$reference]);

        return $label ? $links->withLabel($label) : $links;
    }

    public function release(Reference $reference, ?Label $label = null): ?Release
    {
        $this->incomplete = [];
        $release = null;
        foreach ($this->catalogs as $catalog) {
            // A UPC learnt from an earlier catalogue names the release best; then what was asked, then the ids learnt.
            $learnt = $release ? $this->learnt($reference, $release) : [];
            $found = null;
            foreach ($release?->upc && null === $reference->upc ? [array_shift($learnt), $reference, ...$learnt] : [$reference, ...$learnt] as $asked) {
                try {
                    $found = $catalog->release($asked);
                    break;
                } catch (NotSupportedException) {
                    continue;
                } catch (UnavailableException) {
                    $this->skipped($catalog);
                    break;
                }
            }
            if (null !== $found) {
                $release = $release ? $release->merge($found) : $found;
            }
        }
        if (null === $release) {
            return null;
        }
        // The release's own links first (a store's canonical URL over a redirect), then every catalogue's.
        $links = ($release->links ?? PlatformLinks::none())->merge($this->gather([$reference, ...$this->learnt($reference, $release)]));
        if ($label) {
            $release = $release->withLabel($label);
        }

        return $release->withLinks($release->label ? $links->withLabel($release->label) : $links);
    }

    public function releases(string $artist, int $limit = 50, ?string $country = null): array
    {
        $this->incomplete = [];
        foreach ($this->catalogs as $catalog) {
            try {
                $releases = $catalog->releases($artist, $limit, $country);
            } catch (NotSupportedException) {
                continue;
            } catch (UnavailableException) {
                $this->skipped($catalog);
                continue;
            }
            if ($releases) {
                return $releases;
            }
        }

        return [];
    }

    /**
     * Each catalogue's links, asked by the first reference it can read. One
     * that can read none (Odesli: neither a UPC nor an ISRC) is asked again
     * by the platform ids the others' links carry (the iTunes id), as an
     * album: those links name the release that holds the recording (iTunes
     * answers an ISRC with its album's links), so an id learnt from them is
     * the release's, which a lookup of a song by it would not find.
     *
     * @param list<Reference> $references
     */
    private function gather(array $references): PlatformLinks
    {
        $links = PlatformLinks::none();
        $pending = [];
        foreach ($this->catalogs as $catalog) {
            if (!$this->ask($catalog, $references, $links)) {
                $pending[] = $catalog;
            }
        }
        if ($pending) {
            $learnt = [];
            foreach ($links as $link) {
                if (null !== $link->id) {
                    $learnt[] = Reference::id($link->platform, $link->id, Reference::ALBUM, $references[0]->country);
                }
            }
            foreach ($learnt ? $pending : [] as $catalog) {
                $this->ask($catalog, $learnt, $links);
            }
        }

        return $links;
    }

    /**
     * @param list<Reference> $references
     *
     * @return bool false when the catalogue could read none of them
     */
    private function ask(CatalogInterface $catalog, array $references, PlatformLinks &$links): bool
    {
        foreach ($references as $reference) {
            try {
                $links = $links->merge($catalog->links($reference));

                return true;
            } catch (NotSupportedException) {
                continue;
            } catch (UnavailableException) {
                $this->skipped($catalog);

                return true;
            }
        }

        return false;
    }

    /**
     * Other ways to name a release once a catalogue found it: its UPC
     * (first, when the reference had none), then its id on each platform.
     *
     * @return list<Reference>
     */
    private function learnt(Reference $reference, Release $release): array
    {
        $references = [];
        if ($release->upc && null === $reference->upc) {
            $references[] = Reference::upc($release->upc, $reference->country);
        }
        foreach ($release->ids as $platform => $id) {
            if ($platform = Platform::tryFrom((string) $platform)) {
                $references[] = Reference::id($platform, (string) $id, Release::SINGLE === $release->type && !$release->tracks ? Reference::SONG : Reference::ALBUM, $reference->country);
            }
        }

        return $references;
    }

    private function skipped(CatalogInterface $catalog): void
    {
        if (!\in_array($catalog->getName(), $this->incomplete, true)) {
            $this->incomplete[] = $catalog->getName();
        }
    }
}

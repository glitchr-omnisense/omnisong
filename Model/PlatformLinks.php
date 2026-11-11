<?php

namespace Omnisong\Model;

use Omnisong\Platform;

/**
 * The links of one recording, one per platform, in the order a site shows
 * them: the label first, then the shops, then the streamers (Platform::rank()).
 * Immutable: merge() and with() give a new one.
 *
 * @implements \IteratorAggregate<string, PlatformLink>
 */
final class PlatformLinks implements \IteratorAggregate, \Countable
{
    /** @var array<string, PlatformLink> platform value => link */
    private array $links = [];

    /** @param iterable<PlatformLink> $links */
    public function __construct(iterable $links = [])
    {
        foreach ($links as $link) {
            $this->links[$link->platform->value] ??= $link;
        }
        uasort($this->links, static fn (PlatformLink $a, PlatformLink $b) => [$a->platform->rank(), $a->platform->value] <=> [$b->platform->rank(), $b->platform->value]);
    }

    public static function none(): self
    {
        return new self();
    }

    public function get(Platform $platform): ?PlatformLink
    {
        return $this->links[$platform->value] ?? null;
    }

    public function has(Platform $platform): bool
    {
        return isset($this->links[$platform->value]);
    }

    /** The first one, the label's when there is one. */
    public function first(): ?PlatformLink
    {
        foreach ($this->links as $link) {
            return $link;
        }

        return null;
    }

    public function with(PlatformLink $link): self
    {
        return new self([$link, ...array_values($this->links)]);
    }

    public function withLabel(Label $label): self
    {
        $url = $label->url ?? $label->shopUrl;
        if (null === $url) {
            return $this;
        }
        $links = $this->with(new PlatformLink(Platform::LABEL, $url, null, $label->name));
        if ($label->shopUrl && $label->shopUrl !== $url) {
            $links = $links->with(new PlatformLink(Platform::SHOP, $label->shopUrl, null, $label->name));
        }

        return $links;
    }

    /** Ours first: a platform both have keeps this one's link. */
    public function merge(?self $other): self
    {
        if (null === $other) {
            return $this;
        }

        return new self([...array_values($this->links), ...array_values($other->links)]);
    }

    /** @return list<PlatformLink> */
    public function all(): array
    {
        return array_values($this->links);
    }

    /** @return list<PlatformLink> those the Embedder can play */
    public function embeddable(): array
    {
        return array_values(array_filter($this->links, static fn (PlatformLink $l) => $l->platform->embeddable()));
    }

    /** @return array<string, string> platform value => URL */
    public function toArray(): array
    {
        return array_map(static fn (PlatformLink $l) => $l->url, $this->links);
    }

    /** @param array<array-key, mixed> $urls platform value => URL (unknown platforms, and URLs that are not a non-empty string, skipped) */
    public static function fromArray(array $urls, ?string $labelName = null): self
    {
        $links = [];
        foreach ($urls as $platform => $url) {
            $p = Platform::tryFrom((string) $platform);
            if ($p && \is_string($url) && '' !== $url) {
                $links[] = new PlatformLink($p, $url, null, \in_array($p, [Platform::LABEL, Platform::SHOP], true) ? $labelName : null);
            }
        }

        return new self($links);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->links);
    }

    public function count(): int
    {
        return \count($this->links);
    }
}

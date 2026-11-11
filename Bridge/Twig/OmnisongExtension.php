<?php

namespace Omnisong\Bridge\Twig;

use Omnisong\Model\PlatformLink;
use Omnisong\Platform;
use Omnisong\Player\EmbedderInterface;
use Omnisong\Player\EmbedOptions;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The players and the platforms' names in a template:
 *
 *     {{ omnisong_embed(release.links.get(platform)) }}
 *     {{ omnisong_embed('https://open.spotify.com/album/...', {theme: 'dark', compact: true}) }}
 *     {{ omnisong_platform('apple_music').label }}   {{ 'apple_music'|omnisong_platform_label }}
 */
final class OmnisongExtension extends AbstractExtension
{
    public function __construct(private readonly EmbedderInterface $embedder, private readonly ?EmbedOptions $defaults = null)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('omnisong_embed', $this->embed(...), ['is_safe' => ['html']]),
            new TwigFunction('omnisong_platform', $this->platform(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('omnisong_platform_label', $this->label(...)),
        ];
    }

    /**
     * The iframe that plays a link, or null when its platform has none.
     *
     * @param array{theme?: string, compact?: bool, autoplay?: bool, country?: ?string, color?: ?string} $options over the configured defaults
     */
    public function embed(PlatformLink|string|null $linkOrUrl, array $options = []): ?string
    {
        if (null === $linkOrUrl || '' === $linkOrUrl) {
            return null;
        }
        if (\is_string($linkOrUrl)) {
            $platform = Platform::fromUrl($linkOrUrl);
            if (null === $platform) {
                return null;
            }
            $linkOrUrl = new PlatformLink($platform, $linkOrUrl);
        }
        $defaults = $this->defaults ?? new EmbedOptions();

        return $this->embedder->embed($linkOrUrl, new EmbedOptions(
            (string) ($options['theme'] ?? $defaults->theme),
            (bool) ($options['compact'] ?? $defaults->compact),
            (bool) ($options['autoplay'] ?? $defaults->autoplay),
            $options['country'] ?? $defaults->country,
            $options['color'] ?? $defaults->color,
        ))?->html;
    }

    public function platform(?string $value): ?Platform
    {
        return Platform::tryFrom((string) $value);
    }

    /** "apple_music" (or the Platform itself) as "Apple Music"; what is not a platform stays as it is. */
    public function label(Platform|string|null $value): string
    {
        if ($value instanceof Platform) {
            return $value->label();
        }

        return Platform::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}

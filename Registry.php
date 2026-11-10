<?php

namespace Omnisong;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\Usage;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisong\Catalog\Catalog;
use Omnisong\Catalog\CatalogFactory;
use Omnisong\Catalog\CatalogFactoryInterface;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Exception\InvalidConfigException;
use Omnisong\Exception\UsageLimitReachedException;
use Omnisong\Policy\GuardedCatalog;

/**
 * The catalogues by name, each built once from its factory and options:
 *
 *   new Registry([new OdesliCatalogFactory($http), new ItunesCatalogFactory($http)], [
 *       'odesli' => ['factory' => 'odesli', 'options' => ['api_key' => null]],
 *       'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de'], 'policy' => ['rateLimits' => [['max' => 10, 'window' => 'minute']]]],
 *   ], $limiter);
 *
 * Each catalogue has a usage policy (omnisense/contracts): the one its
 * package declares (policy.json, DeclaresUsagePolicyInterface; unknown
 * otherwise), tightened by the entry's "policy" (never looser), checked
 * against its "usage" (what the site is: commercial, open_source,
 * contract; a use the terms forbid fails when the guard is built). Both may
 * sit in "options" too. get() gives the catalogue itself; guarded() the
 * catalogue behind its guard, catalog() the aggregator over every guarded
 * one: a call over a limit, or while the service's wait lasts, is never
 * made. Every catalogue counts in the limiter given (an in-memory one by
 * default): an application served by many processes gives a shared one.
 */
final class Registry
{
    /** @var array<string, CatalogFactoryInterface> */
    private array $factories = [];

    /** @var array<string, CatalogInterface> */
    private array $catalogs = [];

    /** @var array<string, GuardedCatalog> */
    private array $guarded = [];

    private readonly RateLimiterInterface $limiter;

    /**
     * @param iterable<CatalogFactoryInterface>                                                                                                    $factories
     * @param array<string, array{factory: string, options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>}> $config
     */
    public function __construct(iterable $factories, private readonly array $config, ?RateLimiterInterface $limiter = null)
    {
        foreach ($factories as $factory) {
            $this->factories[$factory->getName()] = $factory;
        }
        $this->limiter = $limiter ?? new InMemoryRateLimiter();
    }

    public function get(string $name): CatalogInterface
    {
        if (isset($this->catalogs[$name])) {
            return $this->catalogs[$name];
        }
        $factory = $this->factory($name);
        $options = $this->config[$name]['options'] ?? [];
        if ($factory instanceof CatalogFactory) {
            // The registry's limiter, which the catalogue's wait is kept in and its guard counts in.
            $options['omnisong.limiter'] = $this->limiter;
        }

        return $this->catalogs[$name] = $factory->create($options);
    }

    /** The catalogue behind its usage policy's guard. */
    public function guarded(string $name): CatalogInterface
    {
        return $this->guarded[$name] ??= new GuardedCatalog($this->get($name), $this->guard($name));
    }

    /**
     * The guard of a catalogue: its policy as the site runs it, the
     * limiter's counts, the waits its service asked for.
     *
     * @throws UsageNotPermitted when the entry's "usage" says a use the policy forbids
     */
    public function guard(string $name): PolicyGuard
    {
        if (isset($this->guarded[$name])) {
            return $this->guarded[$name]->guard();
        }
        $catalog = $this->get($name);
        $declared = $catalog instanceof DeclaresUsagePolicyInterface ? $catalog->usagePolicy() : UsagePolicy::unknown($catalog->getName());
        if (UsagePolicy::UNNAMED === $declared->provider) {
            $declared = $declared->named($catalog->getName());
        }
        $policy = $declared->tighten($this->part($name, 'policy'));
        $usage = $this->part($name, 'usage');
        if ([] !== $usage && [] !== $violations = $policy->violations(Usage::fromArray($usage))) {
            throw UsageNotPermitted::of($policy->provider, $violations);
        }
        $factory = $this->config[$name]['factory'];

        return new PolicyGuard($policy, $this->limiter, CatalogFactory::backoff($factory, $this->limiter), UsageLimitReachedException::class);
    }

    /** A catalogue's usage policy as the site runs it: its package's, tightened by the site's configuration. */
    public function policy(string $name): UsagePolicy
    {
        return $this->guard($name)->policy();
    }

    /**
     * Every configured catalogue's policy, by name: what a site's credits
     * page lists (attribution, licence, the terms' address).
     *
     * @return array<string, UsagePolicy>
     */
    public function policies(): array
    {
        $policies = [];
        foreach ($this->names() as $name) {
            $policies[$name] = $this->policy($name);
        }

        return $policies;
    }

    /** Every configured catalogue, guarded, asked in the configured order as one. */
    public function catalog(): Catalog
    {
        return new Catalog(array_map($this->guarded(...), $this->names()));
    }

    public function has(string $name): bool
    {
        return isset($this->config[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->config);
    }

    /** @return array<string, CatalogInterface> in the configured order */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->config) as $name) {
            $all[$name] = $this->get($name);
        }

        return $all;
    }

    /** @return list<string> the factories installed: what `factory:` may name */
    public function factories(): array
    {
        return array_keys($this->factories);
    }

    private function factory(string $name): CatalogFactoryInterface
    {
        $catalog = $this->config[$name] ?? throw new InvalidConfigException(\sprintf('No "%s" catalogue; configured: %s.', $name, implode(', ', array_keys($this->config)) ?: 'none'));

        return $this->factories[$catalog['factory']] ?? throw new InvalidConfigException(\sprintf('No "%s" factory for the "%s" catalogue; installed: %s.', $catalog['factory'], $name, implode(', ', array_keys($this->factories)) ?: 'none'));
    }

    /**
     * An entry's "policy" or "usage", beside its options or among them.
     *
     * @return array<string, mixed>
     */
    private function part(string $name, string $key): array
    {
        $entry = $this->config[$name] ?? [];
        $value = $entry[$key] ?? $entry['options'][$key] ?? [];
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new InvalidConfigException(\sprintf('The "%s" catalogue\'s "%s" must be a map, %s given.', $name, $key, get_debug_type($value)));
        }
        $map = [];
        foreach ($value as $field => $one) {
            $map[(string) $field] = $one;
        }

        return $map;
    }
}

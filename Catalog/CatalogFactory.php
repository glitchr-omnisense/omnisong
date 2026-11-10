<?php

namespace Omnisong\Catalog;

use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisong\Config;
use Omnisong\Exception\UsageLimitReachedException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Omnibus way, at its simplest: a catalogue's factory fills a Config -
 * its name ("omnisong.factory_name"), the options it needs
 * ("omnisong.required_options") and their defaults - then builds the
 * catalogue from it, on the application's HTTP client when one is given.
 *
 * Before build(), the Config holds the catalogue's wait (Config::backoff()),
 * over the registry's limiter ("omnisong.limiter") or an in-memory one: the
 * factory gives it to the catalogue, whose HTTP calls keep the waits the
 * service asks for (Retry-After, a RateLimit field with nothing left).
 */
abstract class CatalogFactory implements CatalogFactoryInterface
{
    public function __construct(protected readonly ?HttpClientInterface $http = null)
    {
    }

    public function getName(): string
    {
        return $this->createConfig()->string('omnisong.factory_name') ?? '';
    }

    public function create(array $options = []): CatalogInterface
    {
        $config = $this->createConfig($options);
        $required = $config->get('omnisong.required_options', []);
        $config->validateNotEmpty(\is_array($required) ? array_values(array_filter($required, 'is_string')) : []);
        $config['omnisong.backoff'] = self::backoff($config->string('omnisong.factory_name') ?? static::class, $config->get('omnisong.limiter'));

        return $this->build($config);
    }

    /** @param array<string, mixed> $options */
    public function createConfig(array $options = []): Config
    {
        $config = new Config($options);
        $this->populate($config);

        return $config;
    }

    /**
     * A catalogue's wait: kept in the limiter given (the registry's, shared
     * by its guard), under the factory's name.
     */
    public static function backoff(string $factory, mixed $limiter = null): Backoff
    {
        return new Backoff($limiter instanceof RateLimiterInterface ? $limiter : new InMemoryRateLimiter(), $factory, UsageLimitReachedException::class);
    }

    /** The factory's name, its required options, the defaults of the others. */
    abstract protected function populate(Config $c): void;

    /** The catalogue, from a Config that holds everything it needs. */
    abstract protected function build(Config $c): CatalogInterface;
}

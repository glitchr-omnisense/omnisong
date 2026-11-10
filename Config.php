<?php

namespace Omnisong;

use Omnisense\Contracts\Provider\Config as KernelConfig;
use Omnisong\Exception\InvalidConfigException;

/**
 * A catalogue's configuration while its factory builds it: the options
 * given, the factory's defaults under them, and the keys that must be filled.
 * The kernel's Config (omnisense/contracts) under omnisong's prefix: the keys
 * a factory and the registry fill are "omnisong.factory_name",
 * "omnisong.required_options", "omnisong.limiter" and "omnisong.backoff"
 * (backoff(): the catalogue's wait, for its HTTP calls).
 */
final class Config extends KernelConfig
{
    public const PREFIX = 'omnisong';

    /** @param list<string> $keys */
    public function validateNotEmpty(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key) => !isset($this[$key]) || '' === $this[$key] || [] === $this[$key]));
        if ($missing) {
            throw new InvalidConfigException(\sprintf('The "%s" catalogue needs: %s.', $this->string('omnisong.factory_name') ?? '?', implode(', ', $missing)));
        }
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException(str_replace('provider', 'catalogue', $message));
    }
}

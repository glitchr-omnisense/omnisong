<?php

namespace Omnisong\Policy;

use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\UsageLimitReached;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Exception\NotSupportedException;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;

/**
 * A catalogue behind its usage policy's guard: each call is the operation
 * named after its method ("links", "release", "releases"), checked before
 * it is made - a limit reached, or a wait the service asked for still
 * running, refuses it with nothing sent - and counted after by the HTTP
 * requests it sent (CountsRequestsInterface), or one when the catalogue does
 * not say, nothing for a call it does not support.
 *
 * A refusal is the RateLimitedException omnisong throws for a service that
 * asks to slow down (an UnavailableException: the aggregator skips the
 * catalogue and the answer is not to be cached), its previous the guard's
 * UsageLimitReachedException with the limit and the wait.
 */
final class GuardedCatalog implements CatalogInterface
{
    public function __construct(private readonly CatalogInterface $catalog, private readonly PolicyGuard $guard)
    {
    }

    public function getName(): string
    {
        return $this->catalog->getName();
    }

    /** The catalogue itself, unguarded. */
    public function inner(): CatalogInterface
    {
        return $this->catalog;
    }

    public function guard(): PolicyGuard
    {
        return $this->guard;
    }

    public function links(Reference $reference): PlatformLinks
    {
        return $this->call('links', fn () => $this->catalog->links($reference));
    }

    public function release(Reference $reference): ?Release
    {
        return $this->call('release', fn () => $this->catalog->release($reference));
    }

    public function releases(string $artist, int $limit = 50, ?string $country = null): array
    {
        return $this->call('releases', fn () => $this->catalog->releases($artist, $limit, $country));
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function call(string $operation, \Closure $call): mixed
    {
        try {
            $this->guard->check($operation);
        } catch (UsageLimitReached $e) {
            throw new RateLimitedException($this->catalog->getName(), $e->retryAfter, $e);
        }
        $counter = $this->catalog instanceof CountsRequestsInterface ? $this->catalog : null;
        $before = $counter?->sentRequests();
        $sent = 1;
        try {
            return $call();
        } catch (NotSupportedException $e) {
            $sent = 0;

            throw $e;
        } finally {
            if (null !== $counter && null !== $before) {
                $sent = $counter->sentRequests() - $before;
            }
            if ($sent > 0) {
                $this->guard->record($operation, amounts: ['requests' => $sent]);
            }
        }
    }
}

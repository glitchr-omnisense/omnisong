<?php

namespace Omnisong\Exception;

use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * A call refused before it was made: a rate limit of the catalogue's usage
 * policy (as the site tightened it) is reached, or the service asked to
 * wait (a 429 and its Retry-After, a RateLimit field with nothing left).
 * The registry's guarded catalogues give it as the previous exception of a
 * RateLimitedException, the exception omnisong has always thrown for a
 * service that asks to slow down, so that the aggregator skips the
 * catalogue as before.
 */
final class UsageLimitReachedException extends UsageLimitReached implements OmnisongException
{
}

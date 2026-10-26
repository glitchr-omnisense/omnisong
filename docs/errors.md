---
title: Errors
order: 50
---

# Errors

Every exception of the package implements `Omnisong\Exception\OmnisongException` (an interface that
extends `\Throwable`), except the `\InvalidArgumentException` of `Reference::parse()`.

| Exception | Parent | Thrown when |
|---|---|---|
| `InvalidConfigException` | `\LogicException` | the registry is asked for a name not configured, or one whose factory was not given; a factory's required option is missing or empty |
| `NotSupportedException` | `\LogicException` | a catalogue cannot read that kind of reference, or does not do that operation |
| `ProviderException` | `\RuntimeException` | the service answered with an error that is neither "unknown" nor "unavailable" |
| `UnavailableException` | `ProviderException` | the service could not be reached, is down, answered something that is not JSON, or refused the key |
| `RateLimitedException` | `UnavailableException` | the service asks to slow down |
| `\InvalidArgumentException` | - | `Reference::parse()` read neither a URL, a UPC/EAN nor an ISRC |

Not found is never an exception: `null`, an empty `PlatformLinks`, an empty list.

## Properties and messages

`ProviderException` and its children carry:

| Property | Type | |
|---|---|---|
| `catalog` | `string` | the catalogue's name: `itunes`, `odesli` |
| `status` | `?int` | the HTTP status, null for a transport error |
| `getMessage()` | | `[<catalog>] <message>`: `[odesli] HTTP 401 PUBLIC_API_ACCESS_DEPRECATED: a key may now be required (options: api_key, ODESLI_API_KEY)` |
| `getPrevious()` | | the HTTP client's transport exception, for a transport error |

`RateLimitedException` adds `retryAfter` (`?int`, seconds), from the `Retry-After` header; its
message is `[<catalog>] rate limited, retry in <n> s`, or `[<catalog>] rate limited` without one. Its
`status` is always 429, also when the service answered 403 (iTunes).

> [!WARNING]
> Known defect: `Retry-After` is read with `(int)`: written as an HTTP date, it gives `retryAfter = 0`, where the seconds until that date (or null) are expected (iTunes and Odesli alike).

`NotSupportedException` has two named constructors, whose messages say which:
`NotSupportedException::reference('odesli', $reference)` (`The "odesli" catalogue cannot look up
"4015372820954".`) and `NotSupportedException::operation('odesli', "list an artist's releases")`
(`The "odesli" catalogue does not list an artist's releases.`).

## By catalogue

| Answer | iTunes | Odesli |
|---|---|---|
| 2xx, JSON | the answer | the answer |
| 2xx, not JSON | `UnavailableException` | `UnavailableException` |
| 400 `could_not_resolve_entity` | `ProviderException` | unknown (`null`, empty links) |
| 401, 403 | 403: `RateLimitedException`; 401: `ProviderException` | `UnavailableException`, naming the key |
| 404 | unknown (no results) | unknown |
| 429 | `RateLimitedException` | `RateLimitedException` |
| other 4xx | `ProviderException` (`HTTP <status> <errorMessage>`) | `ProviderException` (`HTTP <status> <code>`) |
| 5xx | `UnavailableException` | `UnavailableException` |
| transport error, timeout | `UnavailableException` | `UnavailableException` |

## What to catch

```php
use Omnisong\Exception\NotSupportedException;
use Omnisong\Exception\ProviderException;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UnavailableException;

try {
    $release = $itunes->release($reference);          // one catalogue, called alone
} catch (NotSupportedException) {
    // this catalogue cannot read that reference: ask another
} catch (RateLimitedException $e) {
    // come back in $e->retryAfter seconds (null: unknown)
} catch (UnavailableException) {
    // down or unreachable: show what is cached, do not cache "nothing"
} catch (ProviderException $e) {
    // the service refused the request: $e->status, $e->getMessage()
}
```

Under the aggregator, `NotSupportedException` and `UnavailableException` (with its child) are
caught and the next catalogue asked; the unavailable ones are named in `$catalog->incomplete`. A
`ProviderException` that is not an `UnavailableException` is not caught: it reaches the caller of
`Catalog`. `InvalidConfigException` is a programming or configuration error: let it surface.

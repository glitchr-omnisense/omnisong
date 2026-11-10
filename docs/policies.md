---
title: Usage policies
order: 45
---

# Usage policies

Each catalogue package declares, as data, what its service allows: its rate limits, the rights its
terms give (commercial use, caching, storage, redistribution, attribution) and what every request
must carry. The file is the package's `policy.json`, named in its `composer.json`
(`extra.omnisense.policy`), in the schema of
[omnisense/contracts](https://github.com/glitchr-omnisense/contracts) (`resources/usage-policy.schema.json`).
`vendor/bin/omnisense-policies` prints every policy an application has installed, as one JSON.

## As the catalogues declare them

| Catalogue | Limits | Rights | Credit | Read |
|---|---|---|---|---|
| `itunes` | about 20 calls a minute ("approximately 20 calls per minute (subject to change)") | commercial use for promoting the store's content only; the answers may be cached; previews streamed only, never stored | "Provided courtesy of iTunes", beside the previews, near a store badge | [performance-partners.apple.com/search-api](https://performance-partners.apple.com/search-api), 2026-11-10 |
| `odesli` | unknown: the keyless 10 a minute is withdrawn (401), the keyed limit is agreed with Odesli | unknown: no terms readable | not stated | [the API page](https://linktree.notion.site/API-d0ebe08a5e304a55928405eb682f6741) (renders nothing without a browser), [odesli.co/terms](https://odesli.co/terms) (a Google Drive document), 2026-11-10 |

What is unknown is declared unknown, never guessed: a site that needs Odesli's figures asks
developers@song.link and tightens the policy with them (below).

## The guard

`Registry::guarded($name)` gives the catalogue behind its policy's guard; `Registry::catalog()` the
aggregator over every guarded catalogue, which the Symfony bundle autowires (as `CatalogInterface`,
and each catalogue by its argument name). `Registry::get()` gives the catalogue itself, unguarded,
as before.

- Each call is an operation named after its method: `links`, `release`, `releases`.
- Checked before: a limit reached, or a wait the service asked for still running, refuses the call
  with nothing sent - a `RateLimitedException` (the exception omnisong throws for a service that
  asks to slow down), its previous a `UsageLimitReachedException` with the limit and `retryAfter`.
  The aggregator skips the catalogue and names it in `$incomplete`, as for a 429.
- Counted after, by the HTTP requests the call sent (`Policy\CountsRequestsInterface`, which
  `omnisong/itunes` and `omnisong/odesli` implement): an answer held in memory (Odesli's `links()`
  then `release()` of one recording) costs nothing, a reference the catalogue cannot read costs
  nothing. The check before is conservative: a call that would be answered from memory is refused
  too once the limit is reached. A catalogue that does not count is counted one request a call.
- The waits a service asks for are kept in the registry's limiter: a 429's `Retry-After` (iTunes'
  403 too), a 503's, or a `RateLimit` field with nothing left (draft-ietf-httpapi-ratelimit-headers)
  hold every later call of that catalogue, guarded or not, until the wait is over. Nothing is
  retried: the next call is the caller's.

```php
$registry = new Registry([new ItunesCatalogFactory($http), new OdesliCatalogFactory($http)], [
    'itunes' => ['factory' => 'itunes', 'options' => ['country' => 'de'],
        'policy' => ['rateLimits' => [['max' => 10, 'window' => 'minute']]]],   // a site's own, stricter
    'odesli' => ['factory' => 'odesli', 'options' => ['api_key' => $key],
        'policy' => ['rateLimits' => [['max' => 60, 'window' => 'minute', 'note' => 'our agreement']]],
        'usage' => ['commercial' => false]],
], $limiter);

$registry->policies();   // by name, for a credits page: attribution, licence, the terms' address
```

`policy` tightens the declared policy and never loosens it (a looser limit, caching allowed where
it is unknown, an attribution dropped: `InvalidPolicyException`); `usage` says what the site is, and
a use the terms forbid fails when the guard is built (`UsageNotPermitted`). Both may sit in
`options` too. Without a shared limiter (the third argument, `RateLimiterInterface`; omnibridge/symfony
has one over symfony/rate-limiter), each PHP process counts alone.

In Symfony:

```yaml
omnisong:
    catalogs:
        itunes: { factory: itunes, options: { country: de }, policy: { rateLimits: [{ max: 10, window: minute }] } }
    limiter: app.omnisong_limiter   # optional: a RateLimiterInterface service
```

## The platforms' own rules: links, badges and players

The links omnisong gives and the players its `Embedder` builds call no API, so they carry no
policy; the platforms' brand rules still apply to the page that shows them:

- **Apple Music**: link with the "Listen on Apple Music" badge as Apple supplies it (localised,
  never redrawn, angled, animated, nor given a shadow or glow), "on Apple Music", the name never
  translated ([identity guidelines](https://www.apple.com/itunes/marketing-on-music/identity-guidelines.html)).
  iTunes previews sit near a store badge and say "provided courtesy of iTunes" (`itunes`' policy).
- **Spotify**: the full logo (icon and wordmark; the icon alone only where there is no room), at
  least 70 px (the icon 21 px), green only on black or white; metadata and artwork shown with the
  Spotify brand and linking back to Spotify, artwork uncropped and unaltered, no logo over it
  ([design guidelines](https://developer.spotify.com/documentation/design), read 2026-11-10).
- **YouTube**: a YouTube logo links to YouTube content, fully visible, never altered nor covered;
  the YouTube name is never part of the site's or application's name
  ([branding guidelines](https://developers.google.com/youtube/terms/branding-guidelines), read
  2026-11-10).
- **Deezer**: no badge rules could be read (deezerbrand.com was not reachable from here): unknown.

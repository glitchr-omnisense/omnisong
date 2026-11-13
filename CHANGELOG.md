# Changelog

## [Unreleased]

## [1.0.1] - 2026-11-14

### Changed

- Neutral example data in docs and tests.

## [1.0.0] - 2026-11-13

The first stable release.

### Release

- Requires `omnisense/contracts: ^1.0`: stable versions, so `composer require omnisense/omnisong`
  installs in a project at Composer's default minimum-stability. `extra.branch-alias` names 1.x
  (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's php-package.yml) is callable, and
  `release.yml` runs it on a tag before glitchr-studio/ci's php-release.yml.

### Added

- On `omnisense/contracts`, the kernel of the omni families: `Config`, `Http\Redaction` and the
  exceptions sit on the kernel's (`OmnisongException` extends its `ExceptionInterface`,
  `InvalidConfigException` and `NotSupportedException` its own, `\LogicException`s still), the public
  API unchanged. Usage policies: each catalogue's as its package declares it (`policy.json`,
  `DeclaresUsagePolicyInterface`), tightened by an entry's `policy`, checked against its `usage`;
  `Registry::guarded()`, `catalog()`, `guard()`, `policy()`, `policies()`, a shared limiter as its
  third argument; `Policy\GuardedCatalog` (a call over a limit or during a wait never made, a
  `RateLimitedException` whose previous is the new `UsageLimitReachedException`; counted by the
  requests sent, `Policy\CountsRequestsInterface`). `CatalogFactory::create()` gives the catalogue
  its wait (`Config::backoff()`) over the registry's limiter. The bundle autowires the guarded
  catalogues and takes `policy`, `usage` and `limiter`. `docs/policies.md`, the platforms' badge
  and logo rules with it.
- The harness installs `omnisense/contracts` from its checkout (`OMNISENSE_CONTRACTS`) or GitHub and
  runs its tests, names its entrypoint, stays on Docker's default bridge, and runs PHPStan
  (`phpstan`, level max, the existing errors in `phpstan-baseline.neon`).
- `Omnisong\Http\Redaction`: a request's secrets (`secrets()`), a text without them (`text()`), a
  failure's copy to wrap in its place (`copy()`), a trace without arguments (`withoutArguments()`),
  for the catalogues that call with a key.

### Changed

- PHPStan at level max with an empty baseline: types only (array shapes, closures' signatures, the
  tests' nullables asserted), no behaviour changed. `PlatformLinks::fromArray()` documents what it
  already did with any array: unknown platforms and URLs that are not a non-empty string skipped.

### Fixed

- `Catalog::links()` asks a catalogue that cannot read the reference (Odesli and an ISRC) by the
  ids the other catalogues' links carry as an album's: iTunes answers an ISRC with its album's
  links, and the album's id was asked with the reference's type, `type=song`, which Odesli cannot
  find a song by.
- `Reference::parse()` throws an `InvalidReferenceException` (an `OmnisongException`, and an
  `\InvalidArgumentException` still) for a value it cannot read: a `catch (OmnisongException)` let
  the plain `\InvalidArgumentException` through.
- SoundCloud's `color` is encoded once: `color=%23ff5500`, where it was `%2523ff5500` (SoundCloud
  read `%23ff5500`, no colour).

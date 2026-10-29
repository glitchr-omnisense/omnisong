# Changelog

## [Unreleased]

### Fixed

- `Reference::parse()` throws an `InvalidReferenceException` (an `OmnisongException`, and an
  `\InvalidArgumentException` still) for a value it cannot read: a `catch (OmnisongException)` let
  the plain `\InvalidArgumentException` through.
- SoundCloud's `color` is encoded once: `color=%23ff5500`, where it was `%2523ff5500` (SoundCloud
  read `%23ff5500`, no colour).

# Changelog

## [Unreleased]

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

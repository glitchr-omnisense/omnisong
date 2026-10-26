---
title: The Docker harness
order: 70
---

# The Docker harness

`docker/` runs this package with every `omnisong/*` catalogue installed - from GitHub (branch
1.x), or from the checkouts beside this one when `OMNISONG_PLUGINS=../..` is set in `docker/.env`
- and a console that asks the real services. iTunes needs no key; Odesli refuses keyless calls
(401) and is skipped, and said so, until `ODESLI_API_KEY` is set.

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnisong catalogs
```

| Command | |
|---|---|
| `catalogs` | the catalogues installed and configured |
| `links <reference>` | the recording on every platform, the label first (`--label`, `--label-url`, `--label-shop`, `--country`) |
| `release <reference>` | the release every catalogue knows, merged: its tracks, their previews, its links (JSON) |
| `releases <artist>` | what an artist put out, newest first (`--limit`, `--country`) |
| `embed <url>` | the iframe that plays a link (`--dark`, `--compact`, `--country`) |
| `bare` | plain PHP: the registry built by hand, a release asked by its UPC, what PHP loaded |
| `test` | every package's tests |

```sh
docker compose run --rm omnisong links 4015372820954 --label ES-DUR --label-url https://www.es-dur.de
docker compose run --rm omnisong release 4015372820954 --label ES-DUR --label-url https://www.es-dur.de
docker compose run --rm omnisong releases "Brieuc Vourch" --limit 10
docker compose run --rm omnisong embed https://music.apple.com/de/album/perspectives-concertantes/1793146044 --dark
```

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the catalogue packages installed, puts the aggregator over
it, asks for the release with the UPC 4015372820954, its label first - the real services (iTunes;
Odesli when its key is set), or with `--recorded` the answers kept in `docker/harness/recorded/`
- then lists what PHP loaded and exits 1 if a class of a framework is among it
(`Symfony\Component\DependencyInjection`, `Config`, `HttpKernel`, `HttpFoundation`, a bundle, a
bridge, Doctrine, Twig):

```
$ docker compose run --rm omnisong bare --recorded
Omnisong in bare PHP: the registry built by hand, no bundle, no container.

  1. odesli
  2. itunes

The release with the UPC 4015372820954, from the answers kept in recorded/:
  Perspectives Concertantes - Anaelle Tourret, NDR Elbphilharmonie Orchester, Vasily Petrenko, Stuttgarter Kammerorchester & Bar Avni
  album of 2025-02-28, ES-DUR
  8 tracks, 8 with a preview; the first: Concerto for Harp and Orchestra in E-Flat Major, Op. 74: I. Allegro Moderato (11:17)
  https://audio-ssl.itunes.apple.com/itunes-assets/AudioPreview221/v4/02/3f/df/023fdf56-9772-8b41-3e98-d0985a4b50a6/mzaf_17993483565663500051.plus.aac.p.m4a
  where it is, the label first:
    label         https://www.es-dur.de
    apple_music   https://music.apple.com/de/album/perspectives-concertantes/1793146044
    tidal         https://listen.tidal.com/album/414051173
    amazon_music  https://music.amazon.com/albums/B0DV5P8V78
    itunes        https://music.apple.com/de/album/perspectives-concertantes/1793146044?app=itunes
    amazon        https://amazon.com/dp/B0DV5P8V78

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, a bridge, Doctrine, Twig): none
```

Without `--recorded` and without an Odesli key, the same from iTunes alone (the label, Apple
Music and the iTunes Store). The answers in `recorded/` are the catalogue packages' fixtures:
iTunes' as answered on 2026-10-03, Odesli's written from what album.link showed that day - the
API itself answers 401 without a key.

`bare --recorded --json` prints the same whole - every class loaded, every file since the
autoloader - without a call: `Tests/BareTest.php` runs it in a process of its own and checks the
list. The files Composer requires together with the autoloader are not counted: where Twig is
installed (for the bridge's tests), its function files are loaded by `vendor/autoload.php`
itself, whatever the script does.

The image is `php:8.4-cli-alpine` with Composer; the harness's packages live in the `harness`
volume of the `omnisong-harness` project.

## The entrypoint

`docker/entrypoint.sh` runs `docker/harness/setup.php` first: it writes the harness's
`composer.json` and `phpunit.xml` in the `harness` volume - this core from its checkout, each
catalogue from its checkout under `OMNISONG_PLUGINS` when one is there (its `composer.json` naming
`omnisong/<slug>`), from `https://github.com/glitchr-omnisense/omnisong-<slug>.git` (branch 1.x)
otherwise - and rewrites them only when they change. Then `composer install` the first time,
`composer update` when `composer.json` is newer than the lock, and the command:

| First argument | Runs |
|---|---|
| `test` | `vendor/bin/phpunit` with the other arguments: every package's tests (suites `core`, `odesli`, `itunes`) |
| `bare` | `docker/harness/bin/bare` with the other arguments (`--recorded`, `--json`) |
| `composer`, `sh`, `php` | that command as it is |
| anything else, or nothing | the console, `docker/harness/bin/omnisong` (default: `catalogs`) |

The console's settings come from `docker/.env` (copied from `.env.dist`, git-ignored):
`ODESLI_API_KEY`, `ODESLI_COUNTRY` (`DE` in `.env.dist`), `ITUNES_COUNTRY` (`de`; `us` when unset),
and `OMNISONG_PLUGINS`. A catalogue is configured when its factory is installed; an option left
empty is not passed, so the factory's default applies. The console's errors: a reference it cannot
parse or a configuration error exits 2 (invalid), a catalogue's error exits 1, each with its
message.

The image (`omnisense/omnisong-harness`) is built from `docker/Dockerfile` the first time
`docker compose run` needs it; the first run also installs the packages into the volume, which
needs the network.

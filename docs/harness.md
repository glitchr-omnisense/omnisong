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
docker compose run --rm omnisong links 028948637751 --label Label Exemple --label-url https://label.example.org
docker compose run --rm omnisong release 028948637751 --label Label Exemple --label-url https://label.example.org
docker compose run --rm omnisong releases "Carlos Kleiber" --limit 10
docker compose run --rm omnisong embed https://music.apple.com/de/album/beethoven-symphonies-nos-5-7/1644892939 --dark
```

## The kernel and PHPStan

`omnisense/contracts` comes from its checkout when `OMNISENSE_CONTRACTS` names it in `docker/.env`
(`../../../omnisense-contracts` when it sits beside the family's folder): its tests then run with the
family's. `docker compose run --rm omnisong phpstan` analyses the core and each catalogue at level
max; the errors that were there before are in each package's `phpstan-baseline.neon`, and new code
adds none.

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the catalogue packages installed, puts the aggregator over
it, asks for the release with the UPC 028948637751, its label first - the real services (iTunes;
Odesli when its key is set), or with `--recorded` the answers kept in `docker/harness/recorded/`
- then lists what PHP loaded and exits 1 if a class of a framework is among it
(`Symfony\Component\DependencyInjection`, `Config`, `HttpKernel`, `HttpFoundation`, a bundle, a
bridge, Doctrine, Twig):

```
$ docker compose run --rm omnisong bare --recorded
Omnisong in bare PHP: the registry built by hand, no bundle, no container.

  1. odesli
  2. itunes

The release with the UPC 028948637751, from the answers kept in recorded/:
  Beethoven: Symphonies Nos. 5 & 7 - Wiener Philharmoniker & Carlos Kleiber
  album of 1995-02-20, Label Exemple
  8 tracks, 8 with a preview; the first: Symphony No. 5 in C Minor, Op. 67: I. Allegro con brio (07:22)
  https://audio-ssl.itunes.apple.com/itunes-assets/AudioPreview211/v4/db/19/f5/db19f52a-08d9-1ef4-74bd-c49ccd58ad50/mzaf_11373277724482988321.plus.aac.p.m4a
  where it is, the label first:
    label         https://label.example.org
    apple_music   https://music.apple.com/de/album/beethoven-symphonies-nos-5-7/1644892939
    deezer        https://www.deezer.com/album/361794007
    itunes        https://music.apple.com/de/album/beethoven-symphonies-nos-5-7/1644892939?app=itunes

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, a bridge, Doctrine, Twig): none
```

Without `--recorded` and without an Odesli key, the same from iTunes alone (the label, Apple
Music and the iTunes Store). The answers in `recorded/` are the catalogue packages' fixtures:
iTunes' as answered on 2026-11-14, Odesli's written from what album.link and Deezer's API showed
that day (its Yandex entry invented) - the API itself answers 401 without a key.

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

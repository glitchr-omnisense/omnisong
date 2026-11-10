<?php

namespace Omnisong\Harness;

use Omnisong\Catalog\Catalog;
use Omnisong\Catalog\CatalogFactoryInterface;
use Omnisong\Exception\InvalidConfigException;
use Omnisong\Exception\OmnisongException;
use Omnisong\Model\Label;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Model\Release;
use Omnisong\Platform;
use Omnisong\Player\Embedder;
use Omnisong\Player\EmbedOptions;
use Omnisong\Registry;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The console that asks every catalogue, for real: catalogs, links,
 * release, releases, embed. A catalogue that cannot be reached (Odesli
 * without its key) is skipped, and said so on stderr.
 */
final class Console
{
    /** @var array<string, array{factory: string, needs: list<string>, options: array<string, mixed>}> */
    private array $config;

    /** @var array<string, CatalogFactoryInterface> */
    private array $factories = [];

    private Registry $registry;

    private function __construct()
    {
        $this->config = require __DIR__.'/../config/catalogs.php';
        $http = HttpClient::create(['headers' => ['User-Agent' => 'omnisong-harness/1.x']]);
        foreach (require __DIR__.'/../plugins.php' as [, $class]) {
            if (class_exists($class)) {
                $factory = new $class($http);
                $this->factories[$factory->getName()] = $factory;
            }
        }
        $usable = array_filter($this->config, fn (array $c) => isset($this->factories[$c['factory']]) && !$this->missing($c));
        $this->registry = new Registry($this->factories, array_map(static fn (array $c) => ['factory' => $c['factory'], 'options' => array_filter($c['options'], static fn ($v) => null !== $v)], $usable));
    }

    public static function create(): Application
    {
        $self = new self();
        $reference = new InputArgument('reference', InputArgument::REQUIRED, 'A URL on any platform, a UPC/EAN, or an ISRC');
        $country = new InputOption('country', 'c', InputOption::VALUE_REQUIRED, 'The store to ask (ISO 3166-1 alpha-2)');
        $label = [
            new InputOption('label', null, InputOption::VALUE_REQUIRED, 'The label\'s name: its link goes first'),
            new InputOption('label-url', null, InputOption::VALUE_REQUIRED, 'The label\'s site'),
            new InputOption('label-shop', null, InputOption::VALUE_REQUIRED, 'The label\'s shop page for the release'),
        ];
        $app = new Application('omnisong', '1.x');
        $app->addCommand($self->command('catalogs', 'Which catalogues are installed, and which are configured from .env', [], fn ($in, $out) => $self->catalogs($out)));
        $app->addCommand($self->command('links', 'The recording on every platform, the label first', [$reference, $country, ...$label], fn ($in, $out) => $self->links($in, $out)));
        $app->addCommand($self->command('release', 'The release every catalogue knows, merged: its tracks and their previews, its links (JSON)', [$reference, $country, ...$label], fn ($in, $out) => $self->release($in, $out)));
        $app->addCommand($self->command('releases', 'What an artist put out, newest first', [new InputArgument('artist', InputArgument::REQUIRED), new InputOption('limit', 'l', InputOption::VALUE_REQUIRED, '', '25'), $country], fn ($in, $out) => $self->releases($in, $out)));
        $app->addCommand($self->command('embed', 'The iframe that plays a link', [new InputArgument('url', InputArgument::REQUIRED), new InputOption('dark', null, InputOption::VALUE_NONE), new InputOption('compact', null, InputOption::VALUE_NONE), $country], fn ($in, $out) => $self->embed($in, $out)));

        return $app;
    }

    /** @param list<InputArgument|InputOption> $definition */
    private function command(string $name, string $description, array $definition, \Closure $code): Command
    {
        $command = new Command($name);
        $command->setDescription($description)->setDefinition($definition);
        $command->setCode(function (InputInterface $in, OutputInterface $out) use ($code): int {
            try {
                return $code($in, $out) ?? Command::SUCCESS;
            } catch (InvalidConfigException|\InvalidArgumentException $e) {
                $out->writeln('<error>'.$e->getMessage().'</error>');

                return Command::INVALID;
            } catch (OmnisongException $e) {
                $out->writeln('<error>'.$e->getMessage().'</error>');

                return Command::FAILURE;
            }
        });

        return $command;
    }

    private function catalogs(OutputInterface $out): void
    {
        $table = new Table($out);
        $table->setHeaders(['#', 'Catalogue', 'Factory', 'Installed', 'Configured', 'Options']);
        $i = 0;
        foreach ($this->config as $name => $catalog) {
            $installed = isset($this->factories[$catalog['factory']]);
            $missing = $this->missing($catalog);
            $options = [];
            foreach ($catalog['options'] as $key => $value) {
                $options[] = $key.': '.(null === $value ? '-' : (str_contains($key, 'key') ? substr((string) $value, 0, 4).'…' : (string) $value));
            }
            $configured = match (true) {
                (bool) $missing => '<comment>needs '.implode(', ', $missing).'</comment>',
                !$installed => '',
                'odesli' === $catalog['factory'] && null === $catalog['options']['api_key'] => '<comment>yes, keyless: song.link answers 401, set ODESLI_API_KEY</comment>',
                default => '<info>yes</info>',
            };
            $table->addRow([++$i, $name, $catalog['factory'], $installed ? '<info>yes</info>' : '<comment>no</comment>', $configured, implode(', ', $options)]);
        }
        $table->render();
        $out->writeln('Factories installed: '.(implode(', ', array_keys($this->factories)) ?: 'none'));
    }

    private function links(InputInterface $in, OutputInterface $out): void
    {
        $catalog = $this->catalog();
        $links = $catalog->links($this->reference($in), $this->label($in));
        $this->incomplete($catalog, $out);
        $this->table($out, $links);
    }

    private function release(InputInterface $in, OutputInterface $out): int
    {
        $catalog = $this->catalog();
        $release = $catalog->release($this->reference($in), $this->label($in));
        $this->incomplete($catalog, $out);
        if (null === $release) {
            $this->stderr($out)->writeln('<comment>Not found.</comment>');

            return Command::FAILURE;
        }
        $out->writeln((string) json_encode(self::normalize($release), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    private function releases(InputInterface $in, OutputInterface $out): void
    {
        $catalog = $this->catalog();
        $releases = $catalog->releases($in->getArgument('artist'), max(1, (int) $in->getOption('limit')), $in->getOption('country'));
        $this->incomplete($catalog, $out);
        $table = new Table($out);
        $table->setHeaders(['Released', 'Title', 'Type', 'Artist', 'Label', 'Link']);
        foreach ($releases as $release) {
            $table->addRow([$release->releasedAt?->format('Y-m-d'), $release->title, $release->type, $release->artist, $release->label?->name, $release->links?->first()?->url]);
        }
        $table->render();
    }

    private function embed(InputInterface $in, OutputInterface $out): int
    {
        $url = (string) $in->getArgument('url');
        $platform = Platform::fromUrl($url) ?? throw new \InvalidArgumentException(\sprintf('"%s" is on no platform Omnisong knows.', $url));
        $embed = (new Embedder())->embed(new PlatformLink($platform, $url), new EmbedOptions($in->getOption('dark') ? EmbedOptions::DARK : EmbedOptions::LIGHT, (bool) $in->getOption('compact'), false, $in->getOption('country')));
        if (null === $embed) {
            $this->stderr($out)->writeln(\sprintf('<comment>No player for this %s URL.</comment>', $platform->label()));

            return Command::FAILURE;
        }
        $out->writeln($embed->html);
        $this->stderr($out)->writeln(\sprintf('<info>%s</info>, %d px high, frame-src %s', $platform->label(), $embed->height, parse_url($embed->src, \PHP_URL_SCHEME).'://'.parse_url($embed->src, \PHP_URL_HOST)));

        return Command::SUCCESS;
    }

    private function catalog(): Catalog
    {
        return $this->registry->catalog();
    }

    private function reference(InputInterface $in): Reference
    {
        return Reference::parse((string) $in->getArgument('reference'), $in->getOption('country'));
    }

    private function label(InputInterface $in): ?Label
    {
        $name = $in->getOption('label');

        return null !== $name && '' !== $name ? new Label($name, $in->getOption('label-url'), $in->getOption('label-shop')) : null;
    }

    private function incomplete(Catalog $catalog, OutputInterface $out): void
    {
        foreach ($catalog->incomplete as $name) {
            $hint = 'odesli' === $name && null === ($this->config[$name]['options']['api_key'] ?? null) ? ' (no ODESLI_API_KEY: song.link refuses keyless calls)' : '';
            $this->stderr($out)->writeln(\sprintf('<comment>Incomplete: "%s" could not be reached%s; the answer is the other catalogues\'.</comment>', $name, $hint));
        }
    }

    private function table(OutputInterface $out, PlatformLinks $links): void
    {
        $table = new Table($out);
        $table->setHeaders(['Platform', 'Title', 'URL', 'Id']);
        foreach ($links as $link) {
            $table->addRow([$link->platform->value, $link->title(), $link->url, $link->id]);
        }
        $table->render();
    }

    /** @param array{needs: list<string>} $catalog */
    private function missing(array $catalog): array
    {
        return array_values(array_filter($catalog['needs'], static fn (string $key) => false === getenv($key) || '' === getenv($key)));
    }

    private function stderr(OutputInterface $out): OutputInterface
    {
        return $out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out;
    }

    /** A release as JSON shows it: links as a list, dates as dates. */
    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof PlatformLinks => array_map(self::normalize(...), $value->all()),
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            $value instanceof \UnitEnum => $value instanceof \BackedEnum ? $value->value : $value->name,
            $value instanceof Release, \is_object($value) => array_map(self::normalize(...), get_object_vars($value)),
            \is_array($value) => array_map(self::normalize(...), $value),
            default => $value,
        };
    }
}

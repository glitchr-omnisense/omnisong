<?php

namespace Omnisong\Tests;

use Omnisong\Bridge\Symfony\OmnisongBundle;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Odesli\OdesliCatalogFactory;
use Omnisong\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Omnisong outside Symfony: the harness's bare script (docker/harness/bin/bare)
 * run in a PHP process of its own - this one has loaded the bundle's tests -
 * builds the registry by hand, asks the aggregator over it for a release by
 * its UPC from the answers kept in docker/harness/recorded/, and reports
 * every class PHP loaded on the way and every file since the autoloader
 * (Composer requires Twig's function files with it wherever Twig is
 * installed, whatever the script does). None may be a framework's.
 */
final class BareTest extends TestCase
{
    private const FRAMEWORK = '~^(?:Symfony\\\\Component\\\\(?:DependencyInjection|Config|HttpKernel|HttpFoundation)|Symfony\\\\Bundle|Symfony\\\\Bridge|Doctrine|Twig)\\\\~';
    private const FRAMEWORK_FILES = '~/vendor/(?:symfony/(?:dependency-injection|config|http-kernel|http-foundation|[a-z-]*bundle|[a-z-]*bridge)|doctrine|twig)/~';

    public function testTheRegistryIsBuiltByHandAndNoClassOfAFrameworkIsLoaded(): void
    {
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertContains(Registry::class, self::strings($report['symbols'] ?? null), 'the registry was built there');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
        $plugins = require __DIR__.'/../docker/harness/plugins.php';
        self::assertIsArray($plugins);
        $installed = array_values(array_filter(self::strings(array_column($plugins, 1)), static fn (string $class): bool => class_exists($class)));
        foreach ($installed as $factory) {
            self::assertContains($factory, self::strings($report['symbols'] ?? null), 'every catalogue package installed, its factory built');
        }
        $factories = $report['factories'] ?? null;
        self::assertIsArray($factories);
        self::assertCount(\count($installed), $factories);
    }

    public function testACatalogueAnswersFromRecordedAnswersWithNoClassOfAFrameworkLoaded(): void
    {
        if (!class_exists(ItunesCatalogFactory::class)) {
            self::markTestSkipped('omnisong/itunes is not installed.');
        }
        [$status, $report] = self::php([__DIR__.'/../docker/harness/bin/bare', '--recorded', '--json']);

        self::assertSame(0, $status);
        self::assertTrue($report['recorded']);
        self::assertContains('itunes', self::strings($report['catalogs'] ?? null));
        self::assertSame([], $report['incomplete']);
        $release = $report['release'];
        self::assertIsArray($release);
        self::assertIsArray($release['first']);
        self::assertIsArray($release['links']);
        self::assertSame('Perspectives Concertantes', $release['title']);
        self::assertIsString($release['artist']);
        self::assertStringStartsWith('Anaelle Tourret, NDR Elbphilharmonie Orchester', $release['artist']);
        self::assertSame(['4015372820954', 'album', '2025-02-28'], [$release['upc'], $release['type'], $release['released']]);
        self::assertSame('ES-DUR', $release['label'], 'the label given, before the one the store names');
        self::assertSame([8, 8], [$release['tracks'], $release['previews']]);
        self::assertIsString($release['first']['preview']);
        self::assertStringStartsWith('https://audio-ssl.itunes.apple.com/', $release['first']['preview']);
        self::assertSame('label', array_key_first($release['links']), 'the label first');
        self::assertSame('https://music.apple.com/de/album/perspectives-concertantes/1793146044', $release['links']['apple_music']);
        if (class_exists(OdesliCatalogFactory::class)) {
            self::assertSame('https://listen.tidal.com/album/414051173', $release['links']['tidal'], 'Odesli asked by the id iTunes found');
        }
        self::assertContains('Symfony\\Component\\HttpClient\\MockHttpClient', self::strings($report['symbols'] ?? null), 'the answers came through the HTTP client given');
        self::assertSame([], self::framework($report), 'no class nor file of a framework');
    }

    /** The check is not blind: the same report, once the bundle is loaded, names the framework. */
    public function testTheBundleDoesLoadTheFramework(): void
    {
        if (!class_exists(AbstractBundle::class)) {
            self::markTestSkipped('symfony/http-kernel is not installed.');
        }
        [$status, $report] = self::php(['-r', 'require getenv("OMNISONG_AUTOLOAD"); $autoloaded = get_included_files(); class_exists($argv[1]) || exit(2); echo json_encode(["symbols" => [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()], "files" => array_values(array_diff(get_included_files(), $autoloaded))]);', '--', OmnisongBundle::class]);

        self::assertSame(0, $status);
        $framework = self::framework($report);
        self::assertContains(AbstractBundle::class, $framework);
        self::assertNotEmpty(preg_grep('~/symfony/http-kernel/~', $framework));
    }

    /**
     * @param array<mixed> $report its symbols and files, lists of strings
     *
     * @return list<string> the classes, interfaces, traits and files of a framework among those loaded
     */
    private static function framework(array $report): array
    {
        $symbols = self::strings($report['symbols'] ?? null);
        $files = self::strings($report['files'] ?? null);

        return [...array_values(array_filter($symbols, static fn (string $s): bool => 1 === preg_match(self::FRAMEWORK, $s))), ...array_values(array_filter($files, static fn (string $f): bool => 1 === preg_match(self::FRAMEWORK_FILES, $f)))];
    }

    /** @return list<string> the value, asserted to be a list of strings */
    private static function strings(mixed $value): array
    {
        self::assertIsList($value);
        $strings = [];
        foreach ($value as $item) {
            self::assertIsString($item);
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * Runs PHP apart, on the autoloader of this run.
     *
     * @param list<string> $arguments
     *
     * @return array{int, array<mixed>} the exit status, the JSON printed
     */
    private static function php(array $arguments): array
    {
        $autoload = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $process = proc_open([\PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['OMNISONG_AUTOLOAD' => $autoload] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($out, true);
        self::assertIsArray($report, 'PHP exited '.$status.': '.$err.$out);

        return [$status, $report];
    }
}

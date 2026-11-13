<?php

namespace Omnisong\Tests\Bridge;

use Omnisong\Bridge\Symfony\OmnisongBundle;
use Omnisong\Bridge\Twig\OmnisongExtension;
use Omnisong\Catalog\Catalog;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\Reference;
use Omnisong\Platform;
use Omnisong\Player\EmbedderInterface;
use Omnisong\Player\EmbedOptions;
use Omnisong\Registry;
use Omnisong\Tests\StubCatalog;
use Omnisong\Tests\StubCatalogFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class OmnisongBundleTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function setUp(): void
    {
        StubCatalog::$log = [];
    }

    protected function tearDown(): void
    {
        if ($this->kernel) {
            $dir = $this->kernel->getProjectDir();
            $this->kernel->shutdown();
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                self::assertInstanceOf(\SplFileInfo::class, $file);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    public function testAKernelBootsWithTheCataloguesAskedInTheConfiguredOrder(): void
    {
        $this->kernel = new OmnisongTestKernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $registry = $container->get(Registry::class);
        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame(['second', 'first'], $registry->names(), 'as configured, not alphabetically');
        self::assertContains('odesli', $registry->factories(), 'the packages installed are registered');
        self::assertContains('itunes', $registry->factories());
        self::assertContains('stub', $registry->factories(), 'the application\'s own, autoconfigured');

        $listening = $container->get(Listening::class);
        self::assertInstanceOf(Listening::class, $listening);
        self::assertInstanceOf(Catalog::class, $listening->catalog, 'CatalogInterface is the aggregator');
        self::assertSame(['second', 'first'], $listening->catalog->names());
        self::assertSame('first', $listening->first->getName(), 'one catalogue by its argument name');

        $links = $listening->catalog->links(Reference::upc('028948637751'));
        self::assertSame(['second links 028948637751', 'first links 028948637751'], StubCatalog::$log);
        self::assertSame('https://second.example/028948637751', $links->get(Platform::SPOTIFY)?->url);
        self::assertSame('https://first.example/028948637751', $links->get(Platform::DEEZER)?->url);

        $embed = $listening->embedder->embed(new PlatformLink(Platform::DEEZER, 'https://www.deezer.com/album/302127'));
        self::assertSame('https://widget.deezer.com/widget/dark/album/302127', $embed?->src, 'the player\'s configured theme');
        $options = $container->get(OmnisongTestKernel::OPTIONS);
        self::assertInstanceOf(EmbedOptions::class, $options);
        self::assertSame('fr', $options->country);
    }

    public function testTheTwigFunctions(): void
    {
        $twig = new Environment(new ArrayLoader([
            'embed' => '{{ omnisong_embed(url) }}|{{ omnisong_embed(url, {theme: "light", compact: true}) }}|{{ omnisong_embed("https://label.example.org") is null ? "none" }}',
            'platform' => '{{ omnisong_platform("apple_music").label }}|{{ "spotify"|omnisong_platform_label }}|{{ "myspace"|omnisong_platform_label }}|{{ omnisong_platform("myspace") is null ? "none" }}',
        ]));
        $twig->addExtension(new OmnisongExtension(new \Omnisong\Player\Embedder(), new EmbedOptions(EmbedOptions::DARK)));

        [$default, $light, $none] = explode('|', $twig->render('embed', ['url' => 'https://www.deezer.com/album/302127']));
        self::assertStringStartsWith('<iframe src="https://widget.deezer.com/widget/dark/album/302127"', $default, 'not escaped: safe HTML');
        self::assertStringContainsString('widget/light/album/302127', $light);
        self::assertStringContainsString('height="90"', $light);
        self::assertSame('none', $none);
        self::assertSame('Apple Music|Spotify|myspace|none', $twig->render('platform'));
    }
}

final class Listening
{
    public function __construct(public readonly CatalogInterface $catalog, public readonly CatalogInterface $first, public readonly EmbedderInterface $embedder)
    {
    }
}

final class OmnisongTestKernel extends Kernel
{
    public const OPTIONS = 'test.omnisong.embed_options';

    public function registerBundles(): iterable
    {
        return [new OmnisongBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->register('http_client', MockHttpClient::class);
            $container->register(StubCatalogFactory::class)->setAutoconfigured(true);
            $container->register(Listening::class)->setAutowired(true)->setPublic(true);
            $container->setAlias(self::OPTIONS, EmbedOptions::class)->setPublic(true);
            $container->loadFromExtension('omnisong', [
                'catalogs' => [
                    'second' => ['factory' => 'stub', 'options' => ['name' => 'second', 'platform' => 'spotify']],
                    'first' => ['factory' => 'stub', 'options' => ['name' => 'first', 'platform' => 'deezer']],
                ],
                'player' => ['theme' => 'dark', 'country' => 'fr'],
            ]);
        });
    }

    public function getProjectDir(): string
    {
        return sys_get_temp_dir().'/omnisong-bundle-test-'.getmypid();
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }
}

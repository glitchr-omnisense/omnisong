<?php

namespace Omnisong\Bridge\Symfony;

use Omnisong\Bridge\Twig\OmnisongExtension;
use Omnisong\Catalog\Catalog;
use Omnisong\Catalog\CatalogFactoryInterface;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Itunes\ItunesCatalogFactory;
use Omnisong\Odesli\OdesliCatalogFactory;
use Omnisong\Player\Embedder;
use Omnisong\Player\EmbedderInterface;
use Omnisong\Player\EmbedOptions;
use Omnisong\Registry;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Twig\Extension\AbstractExtension;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Omnisong in a Symfony application: the catalogue packages installed
 * (omnisong/odesli, omnisong/itunes) registered, the catalogues built from
 * configuration and asked in that order by the one CatalogInterface that is
 * autowired, the Embedder with the site's theme, and the Twig functions:
 *
 *     omnisong:
 *         catalogs:
 *             odesli: { factory: odesli, options: { api_key: '%env(default::ODESLI_API_KEY)%', country: 'DE' } }
 *             itunes: { factory: itunes, options: { country: 'de' }, usage: { commercial: false } }
 *         limiter: ~      # a service id: an Omnisense\Contracts\Policy\RateLimiterInterface shared by every process
 *         player:
 *             theme: light
 *             country: ~
 *
 *     public function __construct(CatalogInterface $catalog, EmbedderInterface $embedder) {}
 *     public function __construct(CatalogInterface $itunes) {}   // one catalogue, by its name
 *
 * An application's own factories (a CatalogFactoryInterface) are registered
 * too, autoconfigured. The catalogues autowired are guarded by their usage
 * policy (Registry::guarded()): a catalogue's "policy" tightens it, its
 * "usage" says what the site is.
 */
final class OmnisongBundle extends AbstractBundle
{
    protected string $extensionAlias = 'omnisong';

    /** The catalogue packages this bundle knows, registered when installed. */
    private const FACTORIES = [OdesliCatalogFactory::class, ItunesCatalogFactory::class];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('catalogs')
                    ->info('The catalogues, by name, in the order the aggregator asks them: a factory (odesli, itunes...) and its options.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('factory')->isRequired()->cannotBeEmpty()->end()
                            ->variableNode('options')->defaultValue([])->end()
                            ->variableNode('policy')->info('Tightens the catalogue\'s usage policy (rateLimits, rights, requirements), never looser.')->defaultValue([])->end()
                            ->variableNode('usage')->info('What the site is: commercial, open_source, contract.')->defaultValue([])->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('limiter')->defaultNull()->info('The service id of an Omnisense\\Contracts\\Policy\\RateLimiterInterface every process shares; an in-memory one otherwise.')->end()
                ->arrayNode('player')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('theme')->values([EmbedOptions::LIGHT, EmbedOptions::DARK])->defaultValue(EmbedOptions::LIGHT)->info('The Embedder\'s default: the theme of the pages the players sit in.')->end()
                        ->scalarNode('country')->defaultNull()->info('ISO 3166-1 alpha-2: overrides the store Apple Music opens.')->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array{catalogs: array<string, array{factory: string, options: array<string, mixed>, policy: array<string, mixed>, usage: array<string, mixed>}>, limiter: ?string, player: array{theme: string, country: ?string}} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(CatalogFactoryInterface::class)->addTag('omnisong.catalog_factory');

        $services = $container->services();
        foreach (self::FACTORIES as $factory) {
            if (class_exists($factory)) {
                $services->set($factory)->args([service('http_client')->nullOnInvalid()])->tag('omnisong.catalog_factory');
            }
        }

        $services->set(Registry::class)
            ->args([tagged_iterator('omnisong.catalog_factory'), $config['catalogs'], null !== $config['limiter'] ? service($config['limiter']) : null])
            ->public();

        $services->set(Catalog::class)->factory([self::class, 'aggregate'])->args([service(Registry::class)]);
        $services->alias(CatalogInterface::class, Catalog::class);

        foreach (array_keys($config['catalogs']) as $name) {
            $id = 'omnisong.catalog.'.$name;
            $services->set($id, CatalogInterface::class)->factory([service(Registry::class), 'guarded'])->args([$name]);
            $builder->registerAliasForArgument($id, CatalogInterface::class, $name);
        }

        $services->set(EmbedOptions::class)->args([$config['player']['theme'], false, false, $config['player']['country']]);
        $services->set(Embedder::class)->args([service(EmbedOptions::class)]);
        $services->alias(EmbedderInterface::class, Embedder::class);

        if (class_exists(AbstractExtension::class)) {
            $services->set(OmnisongExtension::class)->args([service(Embedder::class), service(EmbedOptions::class)])->tag('twig.extension');
        }
    }

    /** Every configured catalogue, in the configured order, guarded, as one. */
    public static function aggregate(Registry $registry): Catalog
    {
        return $registry->catalog();
    }
}

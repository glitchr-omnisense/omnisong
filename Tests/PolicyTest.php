<?php

namespace Omnisong\Tests;

use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisong\Catalog\Catalog;
use Omnisong\Catalog\CatalogFactory;
use Omnisong\Catalog\CatalogInterface;
use Omnisong\Config;
use Omnisong\Exception\InvalidConfigException;
use Omnisong\Exception\OmnisongException;
use Omnisong\Exception\RateLimitedException;
use Omnisong\Exception\UsageLimitReachedException;
use Omnisong\Model\PlatformLink;
use Omnisong\Model\PlatformLinks;
use Omnisong\Model\Reference;
use Omnisong\Platform;
use Omnisong\Policy\GuardedCatalog;
use Omnisong\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Each catalogue's usage policy through the registry: a site's limit
 * counted with the call over it never made, the aggregator skipping the
 * catalogue refused as it skips one told to slow down, a tightening
 * accepted, a looser one and a forbidden use refused.
 */
final class PolicyTest extends TestCase
{
    protected function setUp(): void
    {
        StubCatalog::$log = [];
    }

    /** @param array<string, array<string, mixed>> $config */
    private static function registry(array $config, ?InMemoryRateLimiter $limiter = null): Registry
    {
        /* @phpstan-ignore argument.type */
        return new Registry([new StubCatalogFactory()], $config, $limiter);
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $registry = self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first'], 'policy' => ['rateLimits' => [['max' => 2, 'window' => 'minute', 'operations' => ['links']]]]]]);
        $catalog = $registry->guarded('first');
        $reference = Reference::upc('4015372820954');

        $catalog->links($reference);
        $catalog->links($reference);
        try {
            $catalog->links($reference);
            self::fail('The third call is over the limit.');
        } catch (RateLimitedException $e) {
            self::assertSame('first', $e->catalog);
            self::assertInstanceOf(UsageLimitReachedException::class, $e->getPrevious());
            self::assertInstanceOf(OmnisongException::class, $e->getPrevious());
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertSame(['first links 4015372820954', 'first links 4015372820954'], StubCatalog::$log, 'the third never asked');
        self::assertSame(0, $registry->guard('first')->remaining('links'));
        self::assertNotSame($catalog, $registry->get('first'), 'get() gives the catalogue itself');
    }

    public function testACallTheCatalogueDoesNotSupportCostsNothing(): void
    {
        $registry = self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first'], 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'minute']]]]]);

        try {
            $registry->guarded('first')->releases('Anaëlle Tourret');
            self::fail('The stub lists no releases.');
        } catch (\LogicException) {
        }
        self::assertSame(1, $registry->guard('first')->remaining('links'));
    }

    public function testTheAggregatorSkipsACatalogueOverItsLimitAsOneToldToSlowDown(): void
    {
        $registry = new Registry([new StubCatalogFactory(), new OtherStubFactory()], [
            'first' => ['factory' => 'stub', 'options' => ['name' => 'first', 'platform' => 'spotify'], 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'hour']]]],
            'second' => ['factory' => 'other', 'options' => ['name' => 'second']],
        ]);
        $catalog = $registry->catalog();
        self::assertInstanceOf(Catalog::class, $catalog);
        self::assertSame(['first', 'second'], $catalog->names());

        $catalog->links(Reference::upc('4015372820954'));
        self::assertSame([], $catalog->incomplete);
        $links = $catalog->links(Reference::upc('4015372820954'));

        self::assertSame(['first'], $catalog->incomplete, 'skipped, so the half answer is not cached');
        self::assertNull($links->get(Platform::SPOTIFY));
        self::assertNotNull($links->get(Platform::DEEZER));
    }

    public function testAWaitTheServiceAskedForHoldsTheGuardedCallsToo(): void
    {
        $limiter = new InMemoryRateLimiter();
        $registry = self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first']]], $limiter);
        $registry->get('first');
        CatalogFactory::backoff('stub', $limiter)->hold(30, 'stub answered 429');

        $this->expectException(RateLimitedException::class);
        try {
            $registry->guarded('first')->links(Reference::upc('4015372820954'));
        } finally {
            self::assertSame([], StubCatalog::$log, 'nothing asked while the wait lasts');
        }
    }

    public function testAPolicyIsTightenedNeverLoosened(): void
    {
        $tightened = self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first', 'policy' => ['rights' => ['commercial' => 'forbidden']]]]]);
        self::assertSame('forbidden', $tightened->policy('first')->rights->commercial->value, 'the policy given among the options');
        self::assertSame(['first'], array_keys($tightened->policies()));

        $loosened = self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first'], 'policy' => ['rights' => ['commercial' => 'allowed']]]]);
        $this->expectException(InvalidPolicyException::class);
        $loosened->policy('first');
    }

    public function testAUseTheTermsForbidFailsAtOnce(): void
    {
        $registry = self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first'], 'policy' => ['rights' => ['commercial' => 'forbidden']], 'usage' => ['commercial' => true]]]);

        $this->expectException(UsageNotPermitted::class);
        $registry->guarded('first');
    }

    public function testAPolicyOrUsageThatIsNoMapIsAConfigurationError(): void
    {
        $this->expectException(InvalidConfigException::class);
        self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first'], 'policy' => ['a list']]])->policy('first');
    }

    public function testAnUndeclaredPolicyIsUnknownUnderTheCataloguesName(): void
    {
        $policy = self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first']]])->policy('first');

        self::assertSame('first', $policy->provider);
        self::assertFalse($policy->knowsLimits());
    }

    public function testTheFactoryGivesTheCatalogueItsWaitOverTheRegistrysLimiter(): void
    {
        $limiter = new InMemoryRateLimiter();
        $config = (new OtherStubFactory())->createConfig();
        self::assertInstanceOf(Config::class, $config);
        self::assertNull($config->backoff(), 'outside create()');

        (new Registry([$factory = new OtherStubFactory()], ['second' => ['factory' => 'other', 'options' => ['name' => 'second']]], $limiter))->get('second');
        self::assertSame($limiter, $factory->built['omnisong.limiter'] ?? null);
        self::assertInstanceOf(Backoff::class, $factory->built['omnisong.backoff'] ?? null);
    }

    public function testAGuardedCatalogueIsTheCatalogueByName(): void
    {
        $guarded = self::registry(['first' => ['factory' => 'stub', 'options' => ['name' => 'first']]])->guarded('first');

        self::assertInstanceOf(GuardedCatalog::class, $guarded);
        self::assertSame('first', $guarded->getName());
        self::assertInstanceOf(StubCatalog::class, $guarded->inner());
    }
}

/** A second application catalogue, on Deezer, that keeps the Config it was built from. */
final class OtherStubFactory extends CatalogFactory
{
    /** @var array<string, mixed>|null */
    public ?array $built = null;

    protected function populate(Config $c): void
    {
        $c->defaults(['omnisong.factory_name' => 'other', 'omnisong.required_options' => ['name']]);
    }

    protected function build(Config $c): CatalogInterface
    {
        $this->built = $c->getArrayCopy();

        return new StubCatalog($c->string('name') ?? '', static fn (Reference $r) => new PlatformLinks([new PlatformLink(Platform::DEEZER, 'https://deezer.example/'.$r)]));
    }
}

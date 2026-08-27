<?php

declare(strict_types=1);

namespace App\Tests\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\Providers\StandardPartsProvider;
use App\Services\MechanicalParts\FastenerStandardRegistry;
use App\Services\MechanicalParts\MechanicalPartNormalizer;
use App\Services\MechanicalParts\StandardPartsCatalog;
use PHPUnit\Framework\TestCase;

final class StandardPartsProviderTest extends TestCase
{
    private StandardPartsProvider $provider;

    protected function setUp(): void
    {
        $catalog = new StandardPartsCatalog(
            dirname(__DIR__, 4).'/resources/mechanical/bolts/catalog.json'
        );
        $normalizer = new MechanicalPartNormalizer(new FastenerStandardRegistry());
        $this->provider = new StandardPartsProvider($catalog, $normalizer);
    }

    public function testProviderIsAvailableWithoutCredentials(): void
    {
        self::assertTrue($this->provider->isActive());
        self::assertSame('standard_parts', $this->provider->getProviderInfo()->key);
    }

    public function testSearchFindsDINAliasAndExactVariant(): void
    {
        $results = $this->provider->searchByKeyword('DIN 912 M6 x 20');

        self::assertCount(1, $results);
        self::assertSame('iso-4762:M6:20', $results[0]->provider_id);
        self::assertStringContainsString('ISO 4762', $results[0]->name);
    }

    public function testDetailsHaveStableMechanicalParametersAndNoFootprint(): void
    {
        $details = $this->provider->getDetails('iso-4762:M6:20');
        $parameters = [];
        foreach ($details->parameters ?? [] as $parameter) {
            $parameters[$parameter->name] = $parameter;
        }

        self::assertSame(
            'Mechanical -> Fasteners -> Bolts & Screws -> Socket Head Cap Screws',
            $details->category
        );
        self::assertNull($details->footprint);
        self::assertSame('ISO 4762', $parameters['Standard']->value_text);
        self::assertSame(6.0, $parameters['Nominal diameter']->value_typ);
        self::assertSame(1.0, $parameters['Thread pitch']->value_typ);
        self::assertSame(20.0, $parameters['Length']->value_typ);
    }

    public function testRejectsUnknownVariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->getDetails('iso-4762:M99:20');
    }
}

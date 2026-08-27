<?php

declare(strict_types=1);

namespace App\Tests\Services\MechanicalParts;

use App\Services\MechanicalParts\FastenerStandardRegistry;
use App\Services\MechanicalParts\MechanicalPartNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MechanicalPartNormalizerTest extends TestCase
{
    private MechanicalPartNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new MechanicalPartNormalizer(new FastenerStandardRegistry());
    }

    public function testDINRuleClassifiesSocketHeadCapScrew(): void
    {
        $part = $this->normalizer->normalize([
            'standard' => 'DIN 912',
            'designation' => 'M6 x 20',
            'property_class' => '12.9',
            'hardness' => '39 HRC',
        ]);

        self::assertSame('ISO 4762', $part->standard);
        self::assertSame(['DIN 912'], $part->equivalentStandards);
        self::assertSame('Socket head cap screw', $part->hardwareType);
        self::assertSame(
            'Mechanical -> Fasteners -> Bolts & Screws -> Socket Head Cap Screws',
            $part->categoryPath
        );
        self::assertSame('M6', $part->threadDesignation);
        self::assertSame(6.0, $part->nominalDiameter);
        self::assertSame(20.0, $part->length);
        self::assertSame('12.9', $part->propertyClass);
        self::assertSame('39 HRC', $part->hardness);
    }

    public function testPropertyClassAndHardnessBecomeSeparateParameters(): void
    {
        $definition = $this->normalizer->normalize([
            'type' => 'Hexagon head screw',
            'thread' => 'M8',
            'property_class' => '8.8',
            'hardness' => '255 HV',
        ]);

        $parameters = [];
        foreach ($this->normalizer->toParameters($definition) as $parameter) {
            $parameters[$parameter->name] = $parameter;
        }

        self::assertSame('8.8', $parameters['Property class']->value_text);
        self::assertSame('255 HV', $parameters['Hardness']->value_text);
        self::assertSame('Mechanical parameters', $parameters['Hardness']->group);
    }

    #[DataProvider('metricDesignationProvider')]
    public function testMetricDesignationParsing(string $input, ?string $thread, ?float $length): void
    {
        self::assertSame(
            ['thread' => $thread, 'length' => $length],
            $this->normalizer->parseMetricDesignation($input)
        );
    }

    public static function metricDesignationProvider(): iterable
    {
        yield 'coarse thread' => ['ISO 4762 M6 x 20', 'M6', 20.0];
        yield 'explicit pitch' => ['M10x1.25x40', 'M10X1.25', 40.0];
        yield 'decimal comma' => ['M2,5 × 8', 'M2.5', 8.0];
        yield 'not metric' => ['1/4-20 x 1 inch', null, null];
    }

    public function testUnknownMechanicalTypeUsesSafeFallbackCategory(): void
    {
        $part = $this->normalizer->normalize([
            'type' => 'Deep groove bearing',
            'designation' => '6001-2RS',
        ]);

        self::assertSame('Mechanical -> Bearings', $part->categoryPath);
    }
}

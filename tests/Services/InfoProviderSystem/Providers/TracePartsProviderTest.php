<?php

declare(strict_types=1);

namespace App\Tests\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\Providers\TracePartsProvider;
use App\Services\MechanicalParts\FastenerStandardRegistry;
use App\Services\MechanicalParts\MechanicalPartNormalizer;
use App\Settings\InfoProviderSystem\TracePartsSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TracePartsProviderTest extends TestCase
{
    public function testProviderRequiresCredentialsAndExplicitSyndicationApproval(): void
    {
        $settings = $this->settings();
        $settings->syndicationApproved = false;

        $provider = $this->provider($settings, []);

        self::assertFalse($provider->isActive());
        self::assertTrue($provider->getProviderInfo()->expensive);
    }

    public function testExactPartSearchAndDetailsMapping(): void
    {
        $responses = [
            new MockResponse(json_encode([
                'token' => 'jwt-token',
                'expiryDate' => '2099-01-01T00:00:00+00:00',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'partFamilyCode' => 'FAMILY-1',
                'classificationCode' => 'MAKER',
                'partNumber' => 'ABC-M6-20',
                'selectionPath' => 'M6;20',
                'version' => '1',
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'globalInfo' => [
                    'partFamilyInfo' => [
                        'title' => 'Socket head cap screw M6 x 20',
                        'description' => 'Metric socket head cap screw',
                        'partFamilyPictureUrl' => 'https://cdn.example/family.jpg',
                        'partNumberPictureUrl' => 'https://cdn.example/part.jpg',
                        'classificationLabel' => 'Example Fasteners',
                    ],
                ],
                'bomFields' => [
                    ['symbol' => 'STD', 'label' => 'Standard', 'type' => 'Text', 'value' => 'DIN 912', 'unit' => ''],
                    ['symbol' => 'THREAD', 'label' => 'Thread size', 'type' => 'Text', 'value' => 'M6', 'unit' => ''],
                    ['symbol' => 'P', 'label' => 'Thread pitch', 'type' => 'Real', 'value' => '1', 'unit' => 'mm'],
                    ['symbol' => 'L', 'label' => 'Length', 'type' => 'Real', 'value' => '20', 'unit' => 'mm'],
                    ['symbol' => 'CLASS', 'label' => 'Property class', 'type' => 'Text', 'value' => '12.9', 'unit' => ''],
                    ['symbol' => 'HARD', 'label' => 'Hardness', 'type' => 'Text', 'value' => '39 HRC', 'unit' => ''],
                ],
                'documentList' => [
                    'partFamilyDocumentList' => [
                        ['type' => 'PDF', 'title' => 'Datasheet', 'url' => 'https://cdn.example/data.pdf'],
                    ],
                    'technicalDocumentList' => [],
                ],
                'currentConfigurationLinkList' => [
                    'links' => [
                        ['type' => 'ProductPage', 'label' => 'Product', 'value' => 'https://manufacturer.example/ABC-M6-20'],
                    ],
                ],
                'cadFormatList' => [
                    ['cadFormatId' => 1, 'cadFormatName' => 'STEP', 'deliveryMethod' => 1],
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

        $provider = $this->provider($this->settings(), $responses);
        $results = $provider->searchByKeyword('ABC-M6-20');
        self::assertCount(1, $results);
        self::assertSame('ABC-M6-20', $results[0]->mpn);

        $details = $provider->getDetails($results[0]->provider_id);
        $parameters = [];
        foreach ($details->parameters ?? [] as $parameter) {
            $parameters[$parameter->name] = $parameter;
        }

        self::assertSame('Example Fasteners', $details->manufacturer);
        self::assertSame('ISO 4762', $parameters['Standard']->value_text);
        self::assertSame('12.9', $parameters['Property class']->value_text);
        self::assertSame('39 HRC', $parameters['Hardness']->value_text);
        self::assertSame(20.0, $parameters['Length']->value_typ);
        self::assertSame('https://manufacturer.example/ABC-M6-20', $details->provider_url);
        self::assertCount(1, $details->datasheets ?? []);
        self::assertStringContainsString('STEP', $details->notes ?? '');
        self::assertStringContainsString('Powered by TraceParts', $details->notes ?? '');
    }

    public function testMalformedProviderIDIsRejected(): void
    {
        $provider = $this->provider($this->settings(), []);

        $this->expectException(\InvalidArgumentException::class);
        $provider->getDetails('not-an-id');
    }

    /**
     * @param MockResponse[] $responses
     */
    private function provider(TracePartsSettings $settings, array $responses): TracePartsProvider
    {
        return new TracePartsProvider(
            settings: $settings,
            httpClient: new MockHttpClient($responses, TracePartsProvider::BASE_URL),
            partInfoCache: new ArrayAdapter(),
            normalizer: new MechanicalPartNormalizer(new FastenerStandardRegistry()),
        );
    }

    private function settings(): TracePartsSettings
    {
        $reflection = new \ReflectionClass(TracePartsSettings::class);
        /** @var TracePartsSettings $settings */
        $settings = $reflection->newInstanceWithoutConstructor();
        $settings->apiKey = 'api-key';
        $settings->tenantUid = '1b6b536e-a706-4f4b-a445-3b70680ea44f';
        $settings->catalog = 'Example Fasteners';
        $settings->language = 'en';
        $settings->syndicationApproved = true;

        return $settings;
    }
}

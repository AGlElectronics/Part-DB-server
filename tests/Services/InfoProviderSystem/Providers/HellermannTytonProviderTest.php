<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Tests\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\Catalogs\HellermannTytonCatalog;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\ProviderInfoDTO;
use App\Services\InfoProviderSystem\Providers\HellermannTytonProvider;
use App\Services\InfoProviderSystem\Providers\ProviderCapabilities;
use App\Settings\InfoProviderSystem\HellermannTytonSettings;
use App\Tests\SettingsTestHelper;
use PHPUnit\Framework\TestCase;

final class HellermannTytonProviderTest extends TestCase
{
    private HellermannTytonSettings $settings;
    private HellermannTytonProvider $provider;
    private HellermannTytonCatalog $catalog;

    protected function setUp(): void
    {
        $this->settings = SettingsTestHelper::createSettingsDummy(HellermannTytonSettings::class);
        $this->settings->enabled = true;
        $this->catalog = new HellermannTytonCatalog();
        $this->provider = new HellermannTytonProvider($this->catalog, $this->settings);
    }

    public function testGetProviderInfo(): void
    {
        $info = $this->provider->getProviderInfo();

        $this->assertInstanceOf(ProviderInfoDTO::class, $info);
        $this->assertSame('hellermanntyton', $info->key);
        $this->assertSame('HellermannTyton', $info->name);
        $this->assertStringContainsString('heat shrink', $info->description ?? '');
        $this->assertSame('https://www.hellermanntyton.com/', $info->url);
        $this->assertContains(ProviderCapabilities::BASIC, $info->capabilities);
        $this->assertContains(ProviderCapabilities::PARAMETERS, $info->capabilities);
    }

    public function testIsActiveWhenEnabled(): void
    {
        $this->settings->enabled = true;
        $this->assertTrue($this->provider->isActive());
    }

    public function testIsActiveWhenDisabled(): void
    {
        $this->settings->enabled = false;
        $this->assertFalse($this->provider->isActive());
    }

    public function testSearchCableTieByArticleNumber(): void
    {
        $results = $this->provider->searchByKeyword('111-01980');

        $this->assertNotEmpty($results);
        $this->assertSame('111-01980', $results[0]->name);
        $this->assertSame('111-01980', $results[0]->provider_id);
        $this->assertSame('HellermannTyton', $results[0]->manufacturer);
        $this->assertSame('Cable ties and fixings -> Ties', $results[0]->category);
        $this->assertStringContainsString('T18R', $results[0]->description);
    }

    public function testSearchByCompactArticleNumber(): void
    {
        $results = $this->provider->searchByKeyword('11101980');

        $this->assertNotEmpty($results);
        $ids = array_map(static fn ($result) => $result->provider_id, $results);
        $this->assertContains('111-01980', $ids);
    }

    public function testSearchHeatShrinkByType(): void
    {
        $results = $this->provider->searchByKeyword('HIS-PACK');

        $this->assertNotEmpty($results);
        $ids = array_map(static fn ($result) => $result->provider_id, $results);
        $this->assertContains('300-30120', $ids);
        $this->assertSame('Insulation -> Heat shrink', $results[0]->category);
    }

    public function testSearchCableCoveringByType(): void
    {
        $results = $this->provider->searchByKeyword('HEGP03');

        $this->assertNotEmpty($results);
        $ids = array_map(static fn ($result) => $result->provider_id, $results);
        $this->assertContains('170-10300', $ids);
        $this->assertSame('Cable protection -> Sleeves', $results[0]->category);
    }

    public function testSearchZipTieByLogicalDescription(): void
    {
        $results = $this->provider->searchByKeyword('zip tie T18R black');

        $this->assertNotEmpty($results);
        $ids = array_map(static fn ($result) => $result->provider_id, $results);
        $this->assertContains('111-01980', $ids);
        foreach ($results as $result) {
            $this->assertStringContainsStringIgnoringCase('T18R', $result->description);
            $this->assertStringContainsStringIgnoringCase('Black', $result->description);
        }
    }

    public function testGetDetailsCableTie(): void
    {
        $detail = $this->provider->getDetails('111-01980');

        $this->assertSame('111-01980', $detail->name);
        $this->assertSame('111-01980', $detail->mpn);
        $this->assertStringContainsString('T18R', $detail->description);
        $this->assertSame('HellermannTyton', $detail->manufacturer);
        $this->assertSame('Cable ties and fixings -> Ties', $detail->category);
        $this->assertNotNull($detail->manufacturer_profile);
        $this->assertSame('HellermannTyton', $detail->manufacturer_profile->name);
        $this->assertContains('HellermannTyton GmbH', $detail->manufacturer_profile->alternative_names);

        $type = $this->findParameter($detail->parameters ?? [], 'Type');
        $this->assertNotNull($type);
        $this->assertSame('T18R', $type->value_text);
    }

    public function testGetDetailsHeatShrink(): void
    {
        $detail = $this->provider->getDetails('300-30120');

        $this->assertSame('300-30120', $detail->name);
        $this->assertStringContainsString('HIS-PACK', $detail->description);
        $this->assertSame('Insulation -> Heat shrink', $detail->category);

        $ratio = $this->findParameter($detail->parameters ?? [], 'Shrink ratio');
        $this->assertNotNull($ratio);
        $this->assertStringContainsString('2:1', $ratio->value_text ?? '');
    }

    public function testGetDetailsAcceptsCompactId(): void
    {
        $detail = $this->provider->getDetails('17010300');

        $this->assertSame('170-10300', $detail->name);
        $this->assertSame('Cable protection -> Sleeves', $detail->category);
        $this->assertStringContainsString('HEGP03', $detail->description);
    }

    public function testGetIdFromShopUrl(): void
    {
        $this->assertSame(
            '111-01980',
            $this->provider->getIDFromURL('https://www.hellermanntyton.com/products/T18R/111-01980')
        );
        $this->assertSame(
            '300-30120',
            $this->provider->getIDFromURL('https://www.hellermanntyton.co.uk/search?q=300-30120')
        );
        $this->assertContains('hellermanntyton.com', $this->provider->getHandledDomains());
    }

    public function testCatalogHasParsedArticlesFromAllThreeCatalogs(): void
    {
        $this->assertGreaterThan(2000, $this->catalog->count());
        $this->assertNotNull($this->catalog->getArticle('111-01980'));
        $this->assertNotNull($this->catalog->getArticle('300-30120'));
        $this->assertNotNull($this->catalog->getArticle('170-10300'));
    }

    public function testGetDetailsUnknownId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find HellermannTyton article number: NOT-A-REAL-SKU');
        $this->provider->getDetails('NOT-A-REAL-SKU');
    }

    /**
     * @param  ParameterDTO[]  $parameters
     */
    private function findParameter(array $parameters, string $name): ?ParameterDTO
    {
        foreach ($parameters as $parameter) {
            if ($parameter->name === $name) {
                return $parameter;
            }
        }

        return null;
    }
}

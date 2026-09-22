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

use App\Services\InfoProviderSystem\Catalogs\LandefeldCatalog;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\ProviderInfoDTO;
use App\Services\InfoProviderSystem\Providers\LandefeldProvider;
use App\Services\InfoProviderSystem\Providers\ProviderCapabilities;
use App\Settings\InfoProviderSystem\LandefeldSettings;
use App\Tests\SettingsTestHelper;
use PHPUnit\Framework\TestCase;

final class LandefeldProviderTest extends TestCase
{
    private LandefeldSettings $settings;
    private LandefeldProvider $provider;
    private LandefeldCatalog $catalog;

    protected function setUp(): void
    {
        $this->settings = SettingsTestHelper::createSettingsDummy(LandefeldSettings::class);
        $this->settings->enabled = true;
        $this->catalog = new LandefeldCatalog();
        $this->provider = new LandefeldProvider($this->catalog, $this->settings);
    }

    public function testGetProviderInfo(): void
    {
        $info = $this->provider->getProviderInfo();

        $this->assertInstanceOf(ProviderInfoDTO::class, $info);
        $this->assertSame('landefeld', $info->key);
        $this->assertSame('Landefeld', $info->name);
        $this->assertStringContainsString('Atlas 9', $info->description ?? '');
        $this->assertSame('https://www.landefeld.com/', $info->url);
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

    public function testSearchByArticleNumber(): void
    {
        $results = $this->provider->searchByKeyword('IQSG 146 G');

        $this->assertNotEmpty($results);
        $this->assertSame('IQSG 146 G', $results[0]->name);
        $this->assertSame('IQSG 146 G', $results[0]->provider_id);
        $this->assertSame('Landefeld', $results[0]->manufacturer);
        $this->assertSame('Pneumatics -> Tube connectors', $results[0]->category);
        $this->assertStringContainsString('IQSG', $results[0]->provider_url ?? '');
        $this->assertStringContainsString('146', $results[0]->provider_url ?? '');
    }

    public function testSearchByCompactArticleNumber(): void
    {
        $results = $this->provider->searchByKeyword('IQSG146G');

        $this->assertNotEmpty($results);
        $ids = array_map(static fn ($result) => $result->provider_id, $results);
        $this->assertContains('IQSG 146 G', $ids);
    }

    public function testSearchBySeriesAndChapter(): void
    {
        $results = $this->provider->searchByKeyword('PUN hose');

        $this->assertNotEmpty($results);
        $ids = array_map(static fn ($result) => $result->provider_id, $results);
        $this->assertContains('PUN 6x4', $ids);
    }

    public function testSearchByLogicalDescription(): void
    {
        $results = $this->provider->searchByKeyword('straight 6mm');

        $this->assertNotEmpty($results);
        $ids = array_map(static fn ($result) => $result->provider_id, $results);
        $this->assertContains('IQSG 146 G', $ids);
        foreach ($results as $result) {
            $this->assertStringContainsStringIgnoringCase('straight', $result->description);
            $this->assertMatchesRegularExpression('/\b6 mm\b/i', $result->description);
            $this->assertDoesNotMatchRegularExpression('/\b16 mm\b/i', $result->description);
        }
    }

    public function testGetDetails(): void
    {
        $detail = $this->provider->getDetails('IQSG 146 G');

        $this->assertSame('IQSG 146 G', $detail->name);
        $this->assertSame('IQSG 146 G', $detail->mpn);
        $this->assertSame('Straight push-in fitting, G 1/4", 6 mm', $detail->description);
        $this->assertSame('Landefeld', $detail->manufacturer);
        $this->assertSame('Pneumatics -> Tube connectors', $detail->category);
        $this->assertNotNull($detail->manufacturer_profile);
        $this->assertSame('Landefeld', $detail->manufacturer_profile->name);
        $this->assertContains('Landefeld Druckluft und Hydraulik GmbH', $detail->manufacturer_profile->alternative_names);

        $series = $this->findParameter($detail->parameters ?? [], 'Series');
        $this->assertNotNull($series);
        $this->assertSame('IQSG', $series->value_text);

        $pressure = $this->findParameter($detail->parameters ?? [], 'Operating pressure');
        $this->assertNotNull($pressure);
        $this->assertStringContainsString('20 bar', $pressure->value_text ?? '');
    }

    public function testGetDetailsAcceptsCompactId(): void
    {
        $detail = $this->provider->getDetails('GE15LM');

        $this->assertSame('GE 15 LM', $detail->name);
        $this->assertSame('Pneumatics -> Tube connectors', $detail->category);
    }

    public function testGetIdFromShopUrl(): void
    {
        $this->assertSame(
            'IQSG 146 G',
            $this->provider->getIDFromURL('https://www.landefeld.com/item/en/straight-push-in-fitting/IQSG_146_G')
        );
        $this->assertContains('landefeld.com', $this->provider->getHandledDomains());
    }

    public function testCatalogHasParsedArticles(): void
    {
        $this->assertGreaterThan(4000, $this->catalog->count());
        $this->assertNotNull($this->catalog->getArticle('PUN 6x4'));
        $this->assertNotNull($this->catalog->getArticle('GE 15 LM'));
    }

    public function testGetDetailsUnknownId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find Landefeld article number: NOT-A-REAL-SKU');
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

<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
    10| *  (at your option) any later version.
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

use App\Services\InfoProviderSystem\Catalogs\LappAutomotiveCatalog;
use App\Services\InfoProviderSystem\Catalogs\LappCatalog;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\Providers\LappAutomotiveProvider;
use App\Services\InfoProviderSystem\Providers\ProviderCapabilities;
use App\Settings\InfoProviderSystem\LappAutomotiveSettings;
use App\Tests\SettingsTestHelper;
use PHPUnit\Framework\TestCase;

final class LappAutomotiveProviderTest extends TestCase
{
    private LappAutomotiveSettings $settings;
    private LappAutomotiveProvider $provider;
    private LappAutomotiveCatalog $catalog;

    protected function setUp(): void
    {
        $this->settings = SettingsTestHelper::createSettingsDummy(LappAutomotiveSettings::class);
        $this->settings->enabled = true;
        $this->catalog = new LappAutomotiveCatalog();
        $this->provider = new LappAutomotiveProvider($this->catalog, $this->settings);
    }

    public function testGetProviderInfo(): void
    {
        $info = $this->provider->getProviderInfo();

        $this->assertSame('lapp_automotive', $info->key);
        $this->assertSame('LAPP Automotive', $info->name);
        $this->assertStringContainsString('automotive', mb_strtolower($info->description ?? ''));
        $this->assertStringContainsString('heat 125', mb_strtolower($info->description ?? ''));
        $this->assertContains(ProviderCapabilities::BASIC, $info->capabilities);
    }

    public function testIsActiveWhenDisabled(): void
    {
        $this->settings->enabled = false;
        $this->assertFalse($this->provider->isActive());
    }

    public function testSearchByArticleNumber(): void
    {
        $results = $this->provider->searchByKeyword('1249107');

        $this->assertCount(1, $results);
        $this->assertSame('1249107', $results[0]->name);
        $this->assertSame('1249107', $results[0]->provider_id);
        $this->assertSame('ÖLFLEX HEAT 125 SC A 0.34 mm² BK (black)', $results[0]->mpn);
        $this->assertSame('LAPP', $results[0]->manufacturer);
        $this->assertSame('Cables -> Automotive', $results[0]->category);
    }

    public function testSearchByColorAliases(): void
    {
        foreach (['BK', 'black', 'schwarz'] as $keyword) {
            $results = $this->provider->searchByKeyword($keyword);
            $this->assertNotEmpty($results, 'Expected hits for color alias ' . $keyword);
            $ids = array_map(static fn ($result) => $result->provider_id, $results);
            $this->assertContains('1249107', $ids, 'Expected article 1249107 for color alias ' . $keyword);
        }
    }

    public function testSearchByGermanDecimalAndFamily(): void
    {
        $results = $this->provider->searchByKeyword('0,34 SC A');

        $this->assertNotEmpty($results);
        $this->assertSame('1249107', $results[0]->provider_id);
    }

    public function testGetDetails(): void
    {
        $detail = $this->provider->getDetails('1249107');

        $this->assertSame('1249107', $detail->name);
        $this->assertSame('ÖLFLEX HEAT 125 SC A 0.34 mm² BK (black)', $detail->mpn);
        $this->assertSame('UL single core, 0.34 mm², BK / black / schwarz, 100 m ring', $detail->description);
        $this->assertSame('LAPP', $detail->manufacturer);
        $this->assertSame('Cables -> Automotive', $detail->category);
        $this->assertSame(7.0, $detail->mass);
        $this->assertSame(LappCatalog::PART_UNIT, $detail->part_unit);
        $this->assertSame('https://www.lapp.com/en/de/p/607623', $detail->manufacturer_product_url);
        $this->assertNotNull($detail->manufacturer_profile);
        $this->assertSame('LAPP', $detail->manufacturer_profile->name);
        $this->assertContains('U.I. Lapp GmbH', $detail->manufacturer_profile->alternative_names);

        $color = $this->findParameter($detail->parameters ?? [], 'Color');
        $this->assertNotNull($color);
        $this->assertSame('BK (black)', $color->value_text);
        $this->assertSame('BK', $color->symbol);

        $application = $this->findParameter($detail->parameters ?? [], 'Application');
        $this->assertNotNull($application);
        $this->assertSame('automotive', $application->value_text);
    }

    public function testDoesNotReturnHalogenFreeArticles(): void
    {
        $this->assertSame(186, $this->catalog->count());
        $this->assertSame([], $this->provider->searchByKeyword('4725011'));
        $this->assertSame([], $this->provider->searchByKeyword('H07Z1-K Type 2'));
    }

    public function testGetDetailsUnknownId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find LAPP article number: 0000000');
        $this->provider->getDetails('0000000');
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

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

use App\Services\InfoProviderSystem\Catalogs\LappCatalog;
use App\Services\InfoProviderSystem\Catalogs\LappHalogenFreeCatalog;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\Providers\LappHalogenFreeProvider;
use App\Services\InfoProviderSystem\Providers\ProviderCapabilities;
use App\Settings\InfoProviderSystem\LappHalogenFreeSettings;
use App\Tests\SettingsTestHelper;
use PHPUnit\Framework\TestCase;

final class LappHalogenFreeProviderTest extends TestCase
{
    private LappHalogenFreeSettings $settings;
    private LappHalogenFreeProvider $provider;
    private LappHalogenFreeCatalog $catalog;

    protected function setUp(): void
    {
        $this->settings = SettingsTestHelper::createSettingsDummy(LappHalogenFreeSettings::class);
        $this->settings->enabled = true;
        $this->catalog = new LappHalogenFreeCatalog();
        $this->provider = new LappHalogenFreeProvider($this->catalog, $this->settings);
    }

    public function testGetProviderInfo(): void
    {
        $info = $this->provider->getProviderInfo();

        $this->assertSame('lapp_halogen_free', $info->key);
        $this->assertSame('LAPP Halogen-free', $info->name);
        $this->assertStringContainsString('halogen-free', mb_strtolower($info->description ?? ''));
        $this->assertContains(ProviderCapabilities::BASIC, $info->capabilities);
    }

    public function testIsActiveWhenDisabled(): void
    {
        $this->settings->enabled = false;
        $this->assertFalse($this->provider->isActive());
    }

    public function testCatalogIsSeparateFromHeat125AndAutomotive(): void
    {
        $this->assertSame(366, $this->catalog->count());
        $this->assertSame('halogen-free', $this->catalog->getApplicationLabel());
        $this->assertSame([], $this->provider->searchByKeyword('1249107'));
        $this->assertSame([], $this->provider->searchByKeyword('HEAT 125'));
    }

    public function testSearchByArticleNumber(): void
    {
        $results = $this->provider->searchByKeyword('4725011');

        $this->assertCount(1, $results);
        $this->assertSame('4725011', $results[0]->name);
        $this->assertSame('4725011', $results[0]->provider_id);
        $this->assertSame('H05Z-K 90°C 0.5 mm² BK (black)', $results[0]->mpn);
        $this->assertSame('LAPP', $results[0]->manufacturer);
        $this->assertSame('Cables -> Halogen-free', $results[0]->category);
    }

    public function testSearchByFamilyAndColor(): void
    {
        $results = $this->provider->searchByKeyword('H07Z1-K black 1.5');

        $this->assertNotEmpty($results);
        $this->assertSame('4724060', $results[0]->provider_id);
        $this->assertSame('H07Z1-K Type 2 1.5 mm² BK (black)', $results[0]->mpn);
    }

    public function testGetDetailsRing(): void
    {
        $detail = $this->provider->getDetails('4725011');

        $this->assertSame('4725011', $detail->name);
        $this->assertSame('H05Z-K 90°C 0.5 mm² BK (black)', $detail->mpn);
        $this->assertSame('HAR halogen-free single core, 0.5 mm², BK / black / schwarz, 100 m ring', $detail->description);
        $this->assertSame(9.0, $detail->mass);
        $this->assertSame(LappCatalog::PART_UNIT, $detail->part_unit);
        $this->assertSame('https://www.lapp.com/en/de/p/174068', $detail->manufacturer_product_url);

        $application = $this->findParameter($detail->parameters ?? [], 'Application');
        $this->assertNotNull($application);
        $this->assertSame('halogen-free', $application->value_text);

        $od = $this->findParameter($detail->parameters ?? [], 'Outer diameter');
        $this->assertNotNull($od);
        $this->assertSame(2.1, $od->value_min);
        $this->assertSame(2.6, $od->value_max);
    }

    public function testGetDetailsBoxAndDrum(): void
    {
        $box = $this->provider->getDetails('4725011K');
        $this->assertSame('H05Z-K 90°C 0.5 mm² BK (black) 3000 m box', $box->mpn);
        $this->assertStringContainsString('3000 m box', $box->description);

        $drum = $this->provider->getDetails('4726008');
        $this->assertSame('H07Z-K 90°C 35 mm² GNYE (green/yellow) drum', $drum->mpn);
        $this->assertStringContainsString(', drum', $drum->description);
        $this->assertSame('Cables -> Halogen-free', $drum->category);
    }

    public function testGetDetailsUnknownId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find LAPP article number: 1249107');
        $this->provider->getDetails('1249107');
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

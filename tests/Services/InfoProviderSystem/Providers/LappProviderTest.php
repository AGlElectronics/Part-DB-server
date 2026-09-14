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

use App\Services\InfoProviderSystem\Catalogs\LappRegularCatalog;
use App\Services\InfoProviderSystem\DTOs\ProviderInfoDTO;
use App\Services\InfoProviderSystem\Providers\LappProvider;
use App\Services\InfoProviderSystem\Providers\ProviderCapabilities;
use App\Settings\InfoProviderSystem\LappSettings;
use App\Tests\SettingsTestHelper;
use PHPUnit\Framework\TestCase;

final class LappProviderTest extends TestCase
{
    private LappSettings $settings;
    private LappProvider $provider;
    private LappRegularCatalog $catalog;

    protected function setUp(): void
    {
        $this->settings = SettingsTestHelper::createSettingsDummy(LappSettings::class);
        $this->settings->enabled = true;
        $this->catalog = new LappRegularCatalog();
        $this->provider = new LappProvider($this->catalog, $this->settings);
    }

    public function testGetProviderInfo(): void
    {
        $info = $this->provider->getProviderInfo();

        $this->assertInstanceOf(ProviderInfoDTO::class, $info);
        $this->assertSame('lapp', $info->key);
        $this->assertSame('LAPP', $info->name);
        $this->assertStringContainsString('industrial', $info->description ?? '');
        $this->assertSame('https://www.lapp.com/', $info->url);
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

    public function testUsesSeparateCatalogFromAutomotiveAndHalogenFree(): void
    {
        $this->assertSame(0, $this->catalog->count());
        $this->assertSame('industrial regular', $this->catalog->getApplicationLabel());
        $this->assertSame([], $this->provider->searchByKeyword('1249107'));
        $this->assertSame([], $this->provider->searchByKeyword('HEAT 125'));
        $this->assertSame([], $this->provider->searchByKeyword('4725011'));
        $this->assertSame([], $this->provider->searchByKeyword('H05Z-K'));
    }

    public function testGetDetailsUnknownId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find LAPP article number: 1249107');
        $this->provider->getDetails('1249107');
    }
}

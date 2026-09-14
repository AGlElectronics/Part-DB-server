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

use App\Services\InfoProviderSystem\Catalogs\LappAutomotiveCatalog;
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
        $this->assertContains(ProviderCapabilities::BASIC, $info->capabilities);
    }

    public function testIsActiveWhenDisabled(): void
    {
        $this->settings->enabled = false;
        $this->assertFalse($this->provider->isActive());
    }

    public function testUsesSeparateCatalogFromIndustrial(): void
    {
        $this->assertSame(0, $this->catalog->count());
        $this->assertSame('automotive', $this->catalog->getApplicationLabel());
        $this->assertSame([], $this->provider->searchByKeyword('1249107'));
        $this->assertSame([], $this->provider->searchByKeyword('HEAT 125'));
    }

    public function testGetDetailsUnknownId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find LAPP article number: 1249107');
        $this->provider->getDetails('1249107');
    }
}

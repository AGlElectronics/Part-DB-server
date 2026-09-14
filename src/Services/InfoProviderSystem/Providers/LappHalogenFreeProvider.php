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


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\Catalogs\LappHalogenFreeCatalog;
use App\Settings\InfoProviderSystem\LappHalogenFreeSettings;

class LappHalogenFreeProvider extends AbstractLappProvider
{
    public const PROVIDER_KEY = 'lapp_halogen_free';

    public function __construct(LappHalogenFreeCatalog $catalog, LappHalogenFreeSettings $settings)
    {
        parent::__construct($catalog, $settings, LappHalogenFreeSettings::class);
    }

    public function getProviderKey(): string
    {
        return self::PROVIDER_KEY;
    }

    protected function getProviderName(): string
    {
        return 'LAPP Halogen-free';
    }

    protected function getProviderDescription(): string
    {
        return 'Bundled LAPP standard halogen-free HAR catalog (H05Z-K 90°C, H07Z-K 90°C, H07Z1-K Type 2). Separate from the ÖLFLEX HEAT 125 and automotive catalogs.';
    }
}

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


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\Catalogs\LappAutomotiveCatalog;
use App\Settings\InfoProviderSystem\LappAutomotiveSettings;

class LappAutomotiveProvider extends AbstractLappProvider
{
    public const PROVIDER_KEY = 'lapp_automotive';

    public function __construct(LappAutomotiveCatalog $catalog, LappAutomotiveSettings $settings)
    {
        parent::__construct($catalog, $settings, LappAutomotiveSettings::class);
    }

    public function getProviderKey(): string
    {
        return self::PROVIDER_KEY;
    }

    protected function getProviderName(): string
    {
        return 'LAPP Automotive';
    }

    protected function getProviderDescription(): string
    {
        return 'Bundled LAPP automotive cable catalog (ÖLFLEX HEAT 125 single cores). Separate from the industrial and halogen-free HAR catalogs.';
    }
}

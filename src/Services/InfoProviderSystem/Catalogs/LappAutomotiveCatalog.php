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

namespace App\Services\InfoProviderSystem\Catalogs;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Automotive LAPP catalog. Drop additional family JSON files in Resources/lapp/automotive/.
 */
final class LappAutomotiveCatalog extends LappCatalog
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Services/InfoProviderSystem/Resources/lapp/automotive')]
        string $catalogDirectory = __DIR__ . '/../Resources/lapp/automotive',
        #[Autowire('%kernel.project_dir%/src/Services/InfoProviderSystem/Resources/lapp/shared')]
        string $sharedDirectory = __DIR__ . '/../Resources/lapp/shared',
    ) {
        parent::__construct(
            catalogDirectory: $catalogDirectory,
            sharedDirectory: $sharedDirectory,
            applicationLabel: 'automotive',
            defaultCategory: 'Cables -> Automotive',
        );
    }
}

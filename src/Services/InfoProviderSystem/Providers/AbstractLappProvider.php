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

use App\Entity\Parts\ManufacturingStatus;
use App\Services\InfoProviderSystem\Catalogs\LappCatalog;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\ProviderInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;

abstract class AbstractLappProvider implements InfoProviderInterface
{
    /**
     * @param  object{enabled: bool}  $settings
     */
    public function __construct(
        private readonly LappCatalog $catalog,
        private readonly object $settings,
        private readonly string $settingsClass,
    ) {
    }

    abstract public function getProviderKey(): string;

    abstract protected function getProviderName(): string;

    abstract protected function getProviderDescription(): string;

    public function getProviderInfo(): ProviderInfoDTO
    {
        return new ProviderInfoDTO(
            key: $this->getProviderKey(),
            name: $this->getProviderName(),
            description: $this->getProviderDescription(),
            url: 'https://www.lapp.com/',
            disabledHelp: 'Enable this provider in the provider settings.',
            settingsClass: $this->settingsClass,
            capabilities: [
                ProviderCapabilities::BASIC,
                ProviderCapabilities::PARAMETERS,
            ],
        );
    }

    public function isActive(): bool
    {
        return $this->settings->enabled;
    }

    public function searchByKeyword(string $keyword, array $options = []): array
    {
        $results = [];
        foreach ($this->catalog->search($keyword) as $entry) {
            $results[] = $this->toSearchResult($entry);
        }

        return $results;
    }

    public function getDetails(string $id, array $options = []): PartDetailDTO
    {
        $entry = $this->catalog->getArticle($id);
        if ($entry === null) {
            throw new \RuntimeException('Could not find LAPP article number: ' . $id);
        }

        return $this->toPartDetail($entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function toSearchResult(array $entry): SearchResultDTO
    {
        $article = $entry['article'];
        $family = $entry['family'];

        return new SearchResultDTO(
            provider_key: $this->getProviderKey(),
            provider_id: (string) $article['article_number'],
            name: (string) $article['article_number'],
            description: $entry['description'],
            category: (string) $family['category'],
            manufacturer: $this->catalog->getManufacturerProfile()->name,
            mpn: $entry['designation'],
            manufacturing_status: ManufacturingStatus::ACTIVE,
            provider_url: (string) $family['product_url'],
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function toPartDetail(array $entry): PartDetailDTO
    {
        $article = $entry['article'];
        $family = $entry['family'];
        $color = $entry['color'];
        $profile = $this->catalog->getManufacturerProfile();

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: (string) $article['article_number'],
            name: (string) $article['article_number'],
            description: $entry['description'],
            category: (string) $family['category'],
            manufacturer: $profile->name,
            mpn: $entry['designation'],
            manufacturing_status: ManufacturingStatus::ACTIVE,
            provider_url: (string) $family['product_url'],
            parameters: $this->buildParameters($article, $family, $color),
            mass: (float) $article['weight_kg_km'],
            manufacturer_product_url: (string) $family['product_url'],
            part_unit: LappCatalog::PART_UNIT,
            manufacturer_profile: $profile,
        );
    }

    /**
     * @param  array<string, mixed>  $article
     * @param  array<string, mixed>  $family
     * @param  array<string, mixed>  $color
     * @return ParameterDTO[]
     */
    private function buildParameters(array $article, array $family, array $color): array
    {
        $group = 'Cable';

        return [
            ParameterDTO::parseValueField('Cross section', (float) $article['cross_section_mm2'], unit: 'mm²', group: $group),
            ParameterDTO::parseValueField('Number of cores', (float) $family['cores'], group: $group),
            new ParameterDTO(
                name: 'Color',
                value_text: sprintf('%s (%s)', $color['iec'], $color['en']),
                symbol: (string) $color['iec'],
                group: $group,
            ),
            ParameterDTO::parseValueField('Outer diameter', (float) $article['outer_diameter_mm'], unit: 'mm', group: $group),
            new ParameterDTO(name: 'Nominal voltage', value_text: (string) $family['voltage'], group: $group),
            new ParameterDTO(
                name: 'Operating temperature',
                value_min: (float) $family['temperature_min_c'],
                value_max: (float) $family['temperature_max_c'],
                unit: '°C',
                group: $group,
            ),
            ParameterDTO::parseValueField('Copper index', (float) $article['copper_index_kg_km'], unit: 'kg/km', group: $group),
            new ParameterDTO(
                name: 'Packaging',
                value_text: sprintf('%s m %s', LappCatalog::formatCrossSection($article['length_m']), $article['packaging']),
                group: $group,
            ),
            new ParameterDTO(name: 'Type', value_text: (string) $family['type'], group: $group),
            new ParameterDTO(name: 'Application', value_text: $this->catalog->getApplicationLabel(), group: $group),
        ];
    }
}

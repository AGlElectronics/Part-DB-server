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
use App\Services\InfoProviderSystem\Catalogs\LandefeldCatalog;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\ProviderInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Settings\InfoProviderSystem\LandefeldSettings;

class LandefeldProvider implements InfoProviderInterface, URLHandlerInfoProviderInterface
{
    public const PROVIDER_KEY = 'landefeld';

    public function __construct(
        private readonly LandefeldCatalog $catalog,
        private readonly LandefeldSettings $settings,
    ) {
    }

    public function getProviderInfo(): ProviderInfoDTO
    {
        return new ProviderInfoDTO(
            key: self::PROVIDER_KEY,
            name: 'Landefeld',
            description: 'Bundled Landefeld Atlas 9 Compact catalog (pneumatics, hydraulics, industrial supplies). No live API.',
            url: 'https://www.landefeld.com/',
            disabledHelp: 'Enable this provider in the provider settings.',
            settingsClass: LandefeldSettings::class,
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
            throw new \RuntimeException('Could not find Landefeld article number: ' . $id);
        }

        return $this->toPartDetail($entry);
    }

    public function getHandledDomains(): array
    {
        return ['landefeld.com', 'landefeld.de'];
    }

    public function getIDFromURL(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        if (preg_match('#/([A-Z][A-Z0-9]*_[A-Z0-9._-]+)/?$#i', $path, $matches) !== 1) {
            return null;
        }

        return str_replace('_', ' ', $matches[1]);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function toSearchResult(array $entry): SearchResultDTO
    {
        $article = $entry['article'];
        $articleNumber = (string) $article['article_number'];

        return new SearchResultDTO(
            provider_key: self::PROVIDER_KEY,
            provider_id: $articleNumber,
            name: $articleNumber,
            description: (string) $article['description'],
            category: (string) $entry['chapter']['category'],
            manufacturer: $this->catalog->getManufacturerProfile()->name,
            mpn: $articleNumber,
            manufacturing_status: ManufacturingStatus::ACTIVE,
            provider_url: LandefeldCatalog::shopUrl($articleNumber),
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function toPartDetail(array $entry): PartDetailDTO
    {
        $article = $entry['article'];
        $articleNumber = (string) $article['article_number'];
        $profile = $this->catalog->getManufacturerProfile();
        $shopUrl = LandefeldCatalog::shopUrl($articleNumber);

        return new PartDetailDTO(
            provider_key: self::PROVIDER_KEY,
            provider_id: $articleNumber,
            name: $articleNumber,
            description: (string) $article['description'],
            category: (string) $entry['chapter']['category'],
            manufacturer: $profile->name,
            mpn: $articleNumber,
            manufacturing_status: ManufacturingStatus::ACTIVE,
            provider_url: $shopUrl,
            parameters: $this->buildParameters($article, $entry['chapter']),
            manufacturer_product_url: $shopUrl,
            manufacturer_profile: $profile,
        );
    }

    /**
     * @param  array<string, mixed>  $article
     * @param  array<string, mixed>  $chapter
     * @return ParameterDTO[]
     */
    private function buildParameters(array $article, array $chapter): array
    {
        $group = 'Catalog';
        $parameters = [
            new ParameterDTO(name: 'Series', value_text: (string) $article['series'], group: $group),
            new ParameterDTO(name: 'Family', value_text: (string) $article['family'], group: $group),
            new ParameterDTO(name: 'Chapter', value_text: (string) $chapter['name'], group: $group),
        ];

        if (($article['specs'] ?? '') !== '') {
            $parameters[] = new ParameterDTO(name: 'Size / specs', value_text: (string) $article['specs'], group: $group);
        }
        if (($article['temperature'] ?? null) !== null && $article['temperature'] !== '') {
            $parameters[] = new ParameterDTO(name: 'Temperature range', value_text: (string) $article['temperature'], group: $group);
        }
        if (($article['pressure'] ?? null) !== null && $article['pressure'] !== '') {
            $parameters[] = new ParameterDTO(name: 'Operating pressure', value_text: (string) $article['pressure'], group: $group);
        }
        if (($article['materials'] ?? null) !== null && $article['materials'] !== '') {
            $parameters[] = new ParameterDTO(name: 'Materials', value_text: (string) $article['materials'], group: $group);
        }
        if (($article['media'] ?? null) !== null && $article['media'] !== '') {
            $parameters[] = new ParameterDTO(name: 'Media', value_text: (string) $article['media'], group: $group);
        }
        if (isset($article['page']) && $article['page'] !== '' && $article['page'] !== 0) {
            $parameters[] = ParameterDTO::parseValueField('Catalog page', (float) $article['page'], group: $group);
        }

        return $parameters;
    }
}

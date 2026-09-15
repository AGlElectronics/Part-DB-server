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
use App\Services\InfoProviderSystem\Catalogs\HellermannTytonCatalog;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\ProviderInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Settings\InfoProviderSystem\HellermannTytonSettings;

class HellermannTytonProvider implements InfoProviderInterface, URLHandlerInfoProviderInterface
{
    public const PROVIDER_KEY = 'hellermanntyton';

    public function __construct(
        private readonly HellermannTytonCatalog $catalog,
        private readonly HellermannTytonSettings $settings,
    ) {
    }

    public function getProviderInfo(): ProviderInfoDTO
    {
        return new ProviderInfoDTO(
            key: self::PROVIDER_KEY,
            name: 'HellermannTyton',
            description: 'Bundled HellermannTyton catalogs: heat shrink, cable protection, and cable ties/fixings. No live API.',
            url: 'https://www.hellermanntyton.com/',
            disabledHelp: 'Enable this provider in the provider settings.',
            settingsClass: HellermannTytonSettings::class,
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
            throw new \RuntimeException('Could not find HellermannTyton article number: ' . $id);
        }

        return $this->toPartDetail($entry);
    }

    public function getHandledDomains(): array
    {
        return ['hellermanntyton.com', 'hellermanntyton.co.uk', 'hellermanntyton.de'];
    }

    public function getIDFromURL(string $url): ?string
    {
        if (preg_match('/(\d{3}-\d{4,6})/', $url, $matches) === 1) {
            return $matches[1];
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            foreach (['q', 'query', 'text', 'search'] as $key) {
                $value = $params[$key] ?? null;
                if (is_string($value) && preg_match('/(\d{3}-\d{4,6})/', $value, $matches) === 1) {
                    return $matches[1];
                }
            }
        }

        return null;
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
            provider_url: HellermannTytonCatalog::shopUrl($articleNumber),
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
        $shopUrl = HellermannTytonCatalog::shopUrl($articleNumber);

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
            new ParameterDTO(name: 'Type', value_text: (string) $article['type'], group: $group),
            new ParameterDTO(name: 'Family', value_text: (string) $article['family'], group: $group),
            new ParameterDTO(name: 'Catalog', value_text: (string) $chapter['catalog'], group: $group),
        ];

        if (($article['color'] ?? '') !== '' || ($article['color_code'] ?? '') !== '') {
            $color = trim((string) $article['color']);
            $code = trim((string) $article['color_code']);
            $label = $color;
            if ($code !== '' && !str_contains($color, '(' . $code . ')')) {
                $label = $color !== '' ? $color . ' (' . $code . ')' : $code;
            }
            $parameters[] = new ParameterDTO(name: 'Color', value_text: $label, symbol: $code !== '' ? $code : null, group: $group);
        }
        if (($article['specs'] ?? '') !== '') {
            $parameters[] = new ParameterDTO(name: 'Size / specs', value_text: (string) $article['specs'], group: $group);
        }
        if (($article['material'] ?? null) !== null && $article['material'] !== '') {
            $parameters[] = new ParameterDTO(name: 'Material', value_text: (string) $article['material'], group: $group);
        }
        if (($article['temperature'] ?? null) !== null && $article['temperature'] !== '') {
            $parameters[] = new ParameterDTO(name: 'Temperature range', value_text: (string) $article['temperature'], group: $group);
        }
        if (($article['shrink_ratio'] ?? null) !== null && $article['shrink_ratio'] !== '') {
            $parameters[] = new ParameterDTO(name: 'Shrink ratio', value_text: (string) $article['shrink_ratio'], group: $group);
        }
        if (isset($article['page']) && $article['page'] !== '' && $article['page'] !== 0) {
            $parameters[] = ParameterDTO::parseValueField('Catalog page', (float) $article['page'], group: $group);
        }

        return $parameters;
    }
}

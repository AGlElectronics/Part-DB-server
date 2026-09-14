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

use App\Services\InfoProviderSystem\DTOs\ManufacturerProfileDTO;

/**
 * Loads one bundled LAPP catalog (regular or automotive) from JSON.
 *
 * @phpstan-type ColorInfo array{iec: string, en: string, de: string, aliases: list<string>}
 * @phpstan-type FamilyInfo array{
 *     id: string,
 *     name: string,
 *     description: string,
 *     category: string,
 *     product_url: string,
 *     search_aliases: list<string>,
 *     type: string,
 *     voltage: string,
 *     temperature_min_c: int|float,
 *     temperature_max_c: int|float,
 *     cores: int
 * }
 * @phpstan-type ArticleInfo array{
 *     article_number: string,
 *     cross_section_mm2: int|float,
 *     outer_diameter_mm: int|float,
 *     color: string,
 *     packaging: string,
 *     length_m: int|float,
 *     copper_index_kg_km: int|float,
 *     weight_kg_km: int|float
 * }
 * @phpstan-type CatalogEntry array{article: ArticleInfo, family: FamilyInfo, color: ColorInfo, search: string, designation: string, description: string}
 */
abstract class LappCatalog
{
    public const PART_UNIT = 'Meter';

    /** @var array<string, CatalogEntry>|null */
    private ?array $articles = null;

    private ?ManufacturerProfileDTO $manufacturerProfile = null;

    public function __construct(
        private readonly string $catalogDirectory,
        private readonly string $sharedDirectory,
        private readonly string $applicationLabel,
        private readonly string $defaultCategory,
    ) {
    }

    public function getApplicationLabel(): string
    {
        return $this->applicationLabel;
    }

    public function getManufacturerProfile(): ManufacturerProfileDTO
    {
        $this->ensureLoaded();

        return $this->manufacturerProfile;
    }

    /**
     * @return CatalogEntry|null
     */
    public function getArticle(string $articleNumber): ?array
    {
        $this->ensureLoaded();

        return $this->articles[strtoupper($articleNumber)] ?? null;
    }

    /**
     * @return CatalogEntry[]
     */
    public function search(string $keyword, int $limit = 50): array
    {
        $this->ensureLoaded();

        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $normalized = strtoupper($keyword);
        $exact = [];
        if (isset($this->articles[$normalized])) {
            $exact[] = $this->articles[$normalized];
        }

        $tokens = preg_split('/\s+/u', mb_strtolower($keyword)) ?: [];
        $others = [];
        foreach ($this->articles as $key => $entry) {
            // JSON article numbers are numeric strings; PHP may cast them to int array keys.
            if (strtoupper((string) $key) === $normalized) {
                continue;
            }
            $haystack = $entry['search'];
            foreach ($tokens as $token) {
                if ($token === '' || !str_contains($haystack, $token)) {
                    continue 2;
                }
            }
            $others[] = $entry;
        }

        return array_slice(array_merge($exact, $others), 0, $limit);
    }

    public function count(): int
    {
        $this->ensureLoaded();

        return count($this->articles);
    }

    public static function formatCrossSection(int|float $value): string
    {
        $formatted = number_format((float) $value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param  CatalogEntry  $entry
     */
    public static function buildDesignation(array $entry): string
    {
        $article = $entry['article'];
        $color = $entry['color'];
        $mm2 = self::formatCrossSection($article['cross_section_mm2']);
        $designation = sprintf(
            '%s %s mm² %s (%s)',
            $entry['family']['name'],
            $mm2,
            $color['iec'],
            $color['en']
        );

        if (($article['packaging'] ?? '') === 'box') {
            $designation .= sprintf(' %s m box', self::formatCrossSection($article['length_m']));
        }

        return $designation;
    }

    /**
     * @param  CatalogEntry  $entry
     */
    public static function buildDescription(array $entry): string
    {
        $article = $entry['article'];
        $color = $entry['color'];
        $mm2 = self::formatCrossSection($article['cross_section_mm2']);

        return sprintf(
            '%s, %s mm², %s / %s / %s, %s m %s',
            $entry['family']['description'],
            $mm2,
            $color['iec'],
            $color['en'],
            $color['de'],
            self::formatCrossSection($article['length_m']),
            $article['packaging']
        );
    }

    private function ensureLoaded(): void
    {
        if ($this->articles !== null) {
            return;
        }

        $this->articles = [];
        $manufacturer = $this->readSharedJson('manufacturer.json');
        $this->manufacturerProfile = new ManufacturerProfileDTO(
            name: (string) $manufacturer['name'],
            alternative_names: array_values(array_map('strval', $manufacturer['alternative_names'] ?? [])),
            website: isset($manufacturer['website']) ? (string) $manufacturer['website'] : null,
            address: isset($manufacturer['address']) ? (string) $manufacturer['address'] : null,
            comment: isset($manufacturer['comment']) ? (string) $manufacturer['comment'] : null,
        );

        /** @var array<string, ColorInfo> $colors */
        $colors = $this->readSharedJson('colors.json');

        foreach (glob($this->catalogDirectory . '/*.json') ?: [] as $familyFile) {
            $family = $this->readJsonFile($familyFile);
            $familyInfo = [
                'id' => (string) $family['id'],
                'name' => (string) $family['name'],
                'description' => (string) $family['description'],
                'category' => (string) ($family['category'] ?? $this->defaultCategory),
                'product_url' => (string) $family['product_url'],
                'search_aliases' => array_values(array_map('strval', $family['search_aliases'] ?? [])),
                'type' => (string) $family['type'],
                'voltage' => (string) $family['voltage'],
                'temperature_min_c' => $family['temperature_min_c'],
                'temperature_max_c' => $family['temperature_max_c'],
                'cores' => (int) $family['cores'],
            ];

            foreach ($family['articles'] ?? [] as $article) {
                $colorKey = (string) $article['color'];
                if (!isset($colors[$colorKey])) {
                    throw new \RuntimeException('Unknown LAPP color key: ' . $colorKey);
                }

                $entry = [
                    'article' => $article,
                    'family' => $familyInfo,
                    'color' => $colors[$colorKey],
                    'search' => '',
                    'designation' => '',
                    'description' => '',
                ];
                $entry['designation'] = self::buildDesignation($entry);
                $entry['description'] = self::buildDescription($entry);
                $entry['search'] = $this->buildSearchHaystack($entry);
                $this->articles[strtoupper((string) $article['article_number'])] = $entry;
            }
        }
    }

    /**
     * @param  CatalogEntry  $entry
     */
    private function buildSearchHaystack(array $entry): string
    {
        $article = $entry['article'];
        $mm2 = self::formatCrossSection($article['cross_section_mm2']);
        $parts = [
            $article['article_number'],
            $entry['family']['name'],
            $entry['family']['description'],
            $entry['family']['type'],
            $entry['family']['voltage'],
            $entry['designation'],
            $entry['description'],
            $mm2,
            str_replace('.', ',', $mm2),
            $article['packaging'],
            (string) $article['length_m'],
            $this->applicationLabel,
        ];
        foreach ($entry['family']['search_aliases'] as $alias) {
            $parts[] = $alias;
        }
        foreach ($entry['color']['aliases'] as $alias) {
            $parts[] = $alias;
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /**
     * @return array<string, mixed>
     */
    private function readSharedJson(string $filename): array
    {
        return $this->readJsonFile($this->sharedDirectory . '/' . $filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Missing LAPP catalog file: ' . $path);
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid LAPP catalog file: ' . $path);
        }

        return $data;
    }
}

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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Bundled HellermannTyton catalogs: heat shrink, cable protection, and cable ties/fixings.
 *
 * @phpstan-type ArticleInfo array{
 *     article_number: string,
 *     type: string,
 *     family: string,
 *     page: int,
 *     color: string,
 *     color_code: string,
 *     specs: string,
 *     material: string|null,
 *     temperature: string|null,
 *     shrink_ratio: string|null,
 *     section: string,
 *     kind: string,
 *     description: string
 * }
 * @phpstan-type ChapterInfo array{id: string, name: string, category: string, catalog: string}
 * @phpstan-type CatalogEntry array{article: ArticleInfo, chapter: ChapterInfo, search: string, designation: string}
 */
final class HellermannTytonCatalog
{
    public const SHOP_SEARCH_URL = 'https://www.hellermanntyton.com/search?q=';

    /**
     * @var array<string, list<string>>
     */
    private const KIND_ALIASES = [
        'Heat shrink tubing' => ['heat shrink', 'heatshrink', 'shrink tube', 'shrink tubing', 'insulation'],
        'Heat shrink moulded shape' => ['heat shrink', 'heatshrink', 'moulded shape', 'molded shape', 'helashrink', 'boot'],
        'Insulating tubing' => ['insulating tubing', 'helsyn', 'grommet', 'insulation'],
        'Protective sleeve' => ['sleeve', 'sleeving', 'helagaine', 'braided', 'cable covering', 'conduit', 'protection'],
        'Cable tie' => ['cable tie', 'zip tie', 'ziptie', 'tie wrap', 'kabelbinder'],
        'Cable fixing' => ['fixing', 'mount', 'clip', 'cable fixing'],
        'Connector fixing' => ['connector', 'clip', 'fixing'],
    ];

    /** @var array<string, CatalogEntry>|null */
    private ?array $articles = null;

    /** @var array<string, string>|null */
    private ?array $compactKeys = null;

    private ?ManufacturerProfileDTO $manufacturerProfile = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Services/InfoProviderSystem/Resources/hellermanntyton')]
        private readonly string $catalogDirectory = __DIR__ . '/../Resources/hellermanntyton',
    ) {
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

        $normalized = self::normalizeKey($articleNumber);
        if (isset($this->articles[$normalized])) {
            return $this->articles[$normalized];
        }

        $compact = self::compactKey($normalized);

        return $this->articles[$this->compactKeys[$compact] ?? ''] ?? null;
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

        $normalized = self::normalizeKey($keyword);
        $exact = [];
        if (isset($this->articles[$normalized])) {
            $exact[] = $this->articles[$normalized];
        } else {
            $compact = self::compactKey($normalized);
            if (isset($this->compactKeys[$compact])) {
                $exact[] = $this->articles[$this->compactKeys[$compact]];
            }
        }

        $tokens = self::expandSearchTokens(preg_split('/\s+/u', mb_strtolower($keyword)) ?: []);
        $compact = self::compactKey($normalized);
        $others = [];
        foreach ($this->articles as $key => $entry) {
            if ($exact !== [] && ($key === $normalized || $key === ($this->compactKeys[$compact] ?? ''))) {
                continue;
            }
            if ($compact !== '' && strlen($compact) >= 6 && str_contains(self::compactKey((string) $key), $compact)) {
                $others[] = $entry;
                continue;
            }
            $haystack = $entry['search'];
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue 2;
                }
                $pattern = '/\b' . preg_quote($token, '/') . '\b/u';
                if (preg_match($pattern, $haystack) !== 1) {
                    continue 2;
                }
            }
            $others[] = $entry;
        }

        usort($others, static fn (array $left, array $right): int => self::searchScore($right, $tokens) <=> self::searchScore($left, $tokens));

        return array_slice(array_merge($exact, $others), 0, $limit);
    }

    public function count(): int
    {
        $this->ensureLoaded();

        return count($this->articles);
    }

    public static function shopUrl(string $articleNumber): string
    {
        return self::SHOP_SEARCH_URL . rawurlencode($articleNumber);
    }

    public static function normalizeKey(string $articleNumber): string
    {
        return strtoupper(trim(preg_replace('/\s+/u', '', $articleNumber) ?? $articleNumber));
    }

    public static function compactKey(string $articleNumber): string
    {
        return str_replace(['-', '_', ' '], '', self::normalizeKey($articleNumber));
    }

    /**
     * @param  array<string, mixed>  $article
     */
    public static function buildDescription(array $article, string $chapterName = ''): string
    {
        $kind = trim((string) ($article['kind'] ?? ''));
        $type = trim((string) ($article['type'] ?? ''));
        $color = trim((string) ($article['color'] ?? ''));
        $colorCode = trim((string) ($article['color_code'] ?? ''));
        $stored = trim((string) ($article['description'] ?? ''));

        $head = $type !== ''
            ? ($kind !== '' && !str_contains(mb_strtolower($type), mb_strtolower($kind)) ? $kind . ' ' . $type : $type)
            : ($kind !== '' ? $kind : $chapterName);

        $parts = array_values(array_filter([
            $head,
            self::formatColor($color, $colorCode),
            self::sizeLabel($article),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $stored !== '' ? $stored : (string) ($article['article_number'] ?? $chapterName);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public static function expandSearchTokens(array $tokens): array
    {
        $joined = implode(' ', $tokens);
        $aliases = [
            'zip tie' => 'ziptie',
            'heat shrink' => 'heatshrink',
            'cable tie' => 'cabletie',
        ];
        foreach ($aliases as $phrase => $alias) {
            if (str_contains($joined, $phrase)) {
                $tokens[] = $alias;
            }
        }

        $expanded = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '') {
                continue;
            }
            if (preg_match('/^(\d+(?:[.,]\d+)?)(?:mm)$/u', $token, $matches) === 1) {
                $expanded[] = self::formatNumber((float) str_replace(',', '.', $matches[1])) . 'mm';
                continue;
            }
            $next = $tokens[$i + 1] ?? '';
            if (preg_match('/^\d+(?:[.,]\d+)?$/u', $token) === 1 && $next === 'mm') {
                $expanded[] = self::formatNumber((float) str_replace(',', '.', $token)) . 'mm';
                $i++;
                continue;
            }
            $expanded[] = str_replace(['"', '“', '”'], '', $token);
        }

        return $expanded;
    }

    public static function formatNumber(int|float $value): string
    {
        $formatted = number_format((float) $value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param  CatalogEntry  $entry
     * @param  list<string>  $tokens
     */
    private static function searchScore(array $entry, array $tokens): int
    {
        $type = mb_strtolower((string) ($entry['article']['type'] ?? ''));
        $description = mb_strtolower((string) ($entry['article']['description'] ?? ''));
        $score = 0;
        foreach ($tokens as $token) {
            if ($token !== '' && $type === $token) {
                $score += 20;
            } elseif ($token !== '' && str_contains($type, $token)) {
                $score += 10;
            }
            if ($token !== '' && str_contains($description, $token)) {
                $score += 4;
            }
        }

        return $score;
    }

    private function ensureLoaded(): void
    {
        if ($this->articles !== null) {
            return;
        }

        $this->articles = [];
        $this->compactKeys = [];
        $manufacturer = $this->readJsonFile($this->catalogDirectory . '/manufacturer.json');
        $this->manufacturerProfile = new ManufacturerProfileDTO(
            name: (string) $manufacturer['name'],
            alternative_names: array_values(array_map('strval', $manufacturer['alternative_names'] ?? [])),
            website: isset($manufacturer['website']) ? (string) $manufacturer['website'] : null,
            address: isset($manufacturer['address']) ? (string) $manufacturer['address'] : null,
            comment: isset($manufacturer['comment']) ? (string) $manufacturer['comment'] : null,
        );

        foreach (glob($this->catalogDirectory . '/*.json') ?: [] as $chapterFile) {
            if (basename($chapterFile) === 'manufacturer.json') {
                continue;
            }

            $chapter = $this->readJsonFile($chapterFile);
            $chapterInfo = [
                'id' => (string) $chapter['id'],
                'name' => (string) $chapter['name'],
                'category' => (string) $chapter['category'],
                'catalog' => (string) ($chapter['catalog'] ?? $chapter['name']),
            ];

            foreach ($chapter['articles'] ?? [] as $article) {
                $article['description'] = self::buildDescription($article, $chapterInfo['name']);
                $entry = [
                    'article' => $article,
                    'chapter' => $chapterInfo,
                    'designation' => $article['description'],
                    'search' => '',
                ];
                $entry['search'] = $this->buildSearchHaystack($entry);
                $key = self::normalizeKey((string) $article['article_number']);
                $this->articles[$key] = $entry;
                $this->compactKeys[self::compactKey($key)] = $key;
            }
        }
    }

    /**
     * @param  CatalogEntry  $entry
     */
    private function buildSearchHaystack(array $entry): string
    {
        $article = $entry['article'];
        $parts = [
            $article['article_number'],
            self::compactKey((string) $article['article_number']),
            $article['type'] ?? '',
            $article['family'] ?? '',
            $article['description'] ?? '',
            $entry['designation'] ?? '',
            $article['specs'] ?? '',
            $article['color'] ?? '',
            $article['color_code'] ?? '',
            $article['section'] ?? '',
            $article['kind'] ?? '',
            $entry['chapter']['name'],
            $entry['chapter']['category'],
            $entry['chapter']['catalog'],
        ];
        foreach (self::KIND_ALIASES[(string) ($article['kind'] ?? '')] ?? [] as $alias) {
            $parts[] = $alias;
        }
        $size = self::sizeLabel($article);
        if ($size !== null) {
            $parts[] = $size;
            $parts[] = str_replace(' ', '', $size);
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /**
     * @param  array<string, mixed>  $article
     */
    private static function sizeLabel(array $article): ?string
    {
        $type = (string) ($article['type'] ?? '');
        if (preg_match('/(\d+(?:\.\d+)?\s*\/\s*\d+(?:\.\d+)?)/u', $type, $matches) === 1) {
            return str_replace(' ', '', $matches[1]) . ' mm';
        }

        $specs = (string) ($article['specs'] ?? '');
        if (preg_match('/^(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)/u', $specs, $matches) === 1) {
            $left = self::formatNumber((float) $matches[1]);
            $right = self::formatNumber((float) $matches[2]);
            if ((float) $matches[2] >= 10) {
                return $left . ' x ' . $right . ' mm';
            }

            return $left . '-' . $right . ' mm';
        }

        return null;
    }

    private static function formatColor(string $color, string $colorCode): string
    {
        if ($color === '') {
            return $colorCode;
        }
        if ($colorCode !== '' && !str_contains($color, '(' . $colorCode . ')')) {
            return $color . ' (' . $colorCode . ')';
        }

        return $color;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Missing HellermannTyton catalog file: ' . $path);
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid HellermannTyton catalog file: ' . $path);
        }

        return $data;
    }
}

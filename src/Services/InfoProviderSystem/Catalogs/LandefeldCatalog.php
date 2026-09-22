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
 * Bundled Landefeld Atlas 9 Compact catalog parsed from the English PDF.
 *
 * @phpstan-type ArticleInfo array{
 *     article_number: string,
 *     series: string,
 *     family: string,
 *     page: int,
 *     specs: string,
 *     temperature: string|null,
 *     pressure: string|null,
 *     materials: string|null,
 *     media: string|null,
 *     description: string
 * }
 * @phpstan-type ChapterInfo array{id: string, name: string, category: string, chapter: int}
 * @phpstan-type CatalogEntry array{article: ArticleInfo, chapter: ChapterInfo, search: string, designation: string}
 */
final class LandefeldCatalog
{
    public const SHOP_SEARCH_URL = 'https://www.landefeld.com/search?q=';

    /** @var array<string, string> IQS size prefix → thread */
    private const IQS_THREAD_PREFIXES = [
        '112' => '1 1/2"',
        '34' => '3/4"',
        '38' => '3/8"',
        '18' => '1/8"',
        '14' => '1/4"',
        '12' => '1/2"',
        '10' => '1"',
    ];

    /**
     * Series prefix → [kind, shape aliases].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const SERIES_KIND = [
        'IQSG' => ['Straight push-in fitting', ['straight', 'gerade', 'push-in', 'push in', 'steckanschluss', 'fitting']],
        'IQSF' => ['Female push-in fitting', ['female', 'straight', 'gerade', 'push-in', 'push in', 'fitting']],
        'IQSSF' => ['Bulkhead female push-in fitting', ['bulkhead', 'female', 'push-in', 'push in', 'fitting']],
        'IQSL' => ['Elbow push-in fitting', ['elbow', 'angle', 'winkel', 'push-in', 'push in', 'fitting']],
        'IQSLL' => ['Long elbow push-in fitting', ['elbow', 'angle', 'long', 'winkel', 'push-in', 'push in', 'fitting']],
        'IQSLLK' => ['Long elbow push-in fitting', ['elbow', 'angle', 'long', 'winkel', 'push-in', 'push in', 'fitting']],
        'IQSLF' => ['Elbow female push-in fitting', ['elbow', 'female', 'winkel', 'push-in', 'push in', 'fitting']],
        'IQSLV' => ['Elbow push-in fitting', ['elbow', 'angle', 'winkel', 'push-in', 'push in', 'fitting']],
        'IQST' => ['T push-in fitting', ['tee', 'push-in', 'push in', 'fitting']],
        'IQSTL' => ['T push-in fitting', ['tee', 'push-in', 'push in', 'fitting']],
        'IQSW' => ['45° push-in fitting', ['45', 'elbow', 'angle', 'winkel', 'push-in', 'push in', 'fitting']],
        'IQSY' => ['Y push-in fitting', ['push-in', 'push in', 'fitting']],
        'GE' => ['Straight cutting-ring fitting', ['straight', 'gerade', 'cutting ring', 'cutting-ring', 'fitting']],
        'W' => ['Elbow cutting-ring fitting', ['elbow', 'angle', 'winkel', 'cutting ring', 'fitting']],
        'WE' => ['Elbow cutting-ring fitting', ['elbow', 'angle', 'winkel', 'cutting ring', 'fitting']],
        'EW' => ['Elbow cutting-ring fitting', ['elbow', 'angle', 'winkel', 'cutting ring', 'fitting']],
        'T' => ['T cutting-ring fitting', ['tee', 'cutting ring', 'fitting']],
        'ET' => ['T cutting-ring fitting', ['tee', 'cutting ring', 'fitting']],
        'EL' => ['Elbow cutting-ring fitting', ['elbow', 'angle', 'cutting ring', 'fitting']],
        'PUN' => ['Polyurethane hose', ['hose', 'tube', 'polyurethane', 'pu']],
        'PU' => ['Polyurethane hose', ['hose', 'tube', 'polyurethane', 'pu']],
        'PA' => ['Polyamide hose', ['hose', 'tube', 'polyamide', 'pa']],
    ];

    /** @var array<string, CatalogEntry>|null */
    private ?array $articles = null;

    /** @var array<string, string>|null */
    private ?array $compactKeys = null;

    private ?ManufacturerProfileDTO $manufacturerProfile = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Services/InfoProviderSystem/Resources/landefeld')]
        private readonly string $catalogDirectory = __DIR__ . '/../Resources/landefeld',
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

        $compact = str_replace(' ', '', $normalized);

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
        }

        $tokens = self::expandSearchTokens(preg_split('/\s+/u', mb_strtolower($keyword)) ?: []);
        $compact = str_replace(' ', '', mb_strtolower($keyword));
        $others = [];
        foreach ($this->articles as $key => $entry) {
            if ($key === $normalized) {
                continue;
            }
            if ($compact !== '' && !str_contains($compact, 'mm') && str_contains(str_replace(' ', '', mb_strtolower((string) $key)), $compact)) {
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

    /**
     * @param  CatalogEntry  $entry
     * @param  list<string>  $tokens
     */
    private static function searchScore(array $entry, array $tokens): int
    {
        $description = mb_strtolower((string) ($entry['article']['description'] ?? ''));
        $series = strtoupper((string) ($entry['article']['series'] ?? ''));
        $score = 0;
        if (str_starts_with($series, 'IQS')) {
            $score += 30;
        }
        if (str_contains($description, 'push-in')) {
            $score += 15;
        }
        foreach ($tokens as $token) {
            $needle = str_ends_with($token, 'mm') ? str_replace('mm', ' mm', $token) : $token;
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $description) === 1
                || preg_match('/\b' . preg_quote($token, '/') . '\b/u', $description) === 1
            ) {
                $score += 8;
            }
        }

        return $score;
    }

    public static function shopUrl(string $articleNumber): string
    {
        return self::SHOP_SEARCH_URL . rawurlencode($articleNumber);
    }

    public static function normalizeKey(string $articleNumber): string
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/u', ' ', $articleNumber) ?? $articleNumber));

        return str_replace(['_', '-'], [' ', ' '], $normalized);
    }

    /**
     * Logical designation used as the part description, e.g. "Straight push-in fitting, G 1/4\", 6 mm".
     *
     * @param  array<string, mixed>  $article
     */
    public static function buildDescription(array $article, string $chapterName = ''): string
    {
        $decoded = self::decodeArticle($article);
        $parts = array_values(array_filter([
            $decoded['kind'],
            $decoded['thread'],
            $decoded['size'],
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        $family = trim((string) ($article['family'] ?? ''));
        $specs = trim((string) ($article['specs'] ?? ''));
        if ($family !== '' && $specs !== '') {
            return $family . ', ' . $specs;
        }

        return $family !== '' ? $family : ($chapterName !== '' ? $chapterName : (string) $article['article_number']);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public static function expandSearchTokens(array $tokens): array
    {
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
            if (preg_match('/^(\d+)x(\d+(?:[.,]\d+)?)$/u', $token, $matches) === 1) {
                $expanded[] = $matches[1] . 'x' . str_replace(',', '.', $matches[2]);
                continue;
            }
            $expanded[] = str_replace(['"', '“', '”'], '', $token);
        }

        return $expanded;
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array{kind: string, thread: string|null, size: string|null, aliases: list<string>}
     */
    public static function decodeArticle(array $article): array
    {
        $series = strtoupper((string) ($article['series'] ?? ''));
        $number = (string) ($article['article_number'] ?? '');
        $specs = (string) ($article['specs'] ?? '');
        $family = mb_strtolower((string) ($article['family'] ?? ''));

        $kind = $article['family'] ?? $series;
        $aliases = [];
        $profile = self::seriesProfile($series);
        if ($profile !== null) {
            [$kind, $aliases] = $profile;
        }
        if ($aliases === []) {
            if (str_contains($family, 'push in') || str_contains($family, 'push-in')) {
                $aliases = ['push-in', 'push in', 'fitting'];
                if (str_contains($family, 'straight') || str_starts_with($series, 'IQSG')) {
                    $kind = is_string($kind) && $kind !== '' ? (string) $kind : 'Straight push-in fitting';
                    $aliases[] = 'straight';
                }
            } elseif (str_contains($family, 'hose') || str_contains($family, 'tube')) {
                $aliases = ['hose', 'tube'];
            } elseif (str_contains($family, 'cutting ring')) {
                $aliases = ['cutting ring', 'fitting'];
            }
        }

        $sizeToken = self::sizeToken($number, $series);
        $thread = self::threadFromIqsToken($sizeToken, $number, $specs, $series);
        $tubeMm = self::tubeMmFromIqsToken($sizeToken, $specs, $number, $series);

        if (in_array($series, ['PUN', 'PU', 'PA'], true) && preg_match('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/u', $sizeToken . ' ' . $specs, $hose) === 1) {
            $od = self::formatNumber((float) str_replace(',', '.', $hose[1]));
            $id = self::formatNumber((float) str_replace(',', '.', $hose[2]));
            $tubeMm = $od;
            $sizeLabel = $od . ' x ' . $id . ' mm';
        } elseif ($tubeMm !== null) {
            $sizeLabel = $tubeMm . ' mm';
        } else {
            $sizeLabel = null;
        }

        if ($thread !== null) {
            $aliases[] = mb_strtolower(str_replace(['"', ' '], ['', ''], $thread));
            $aliases[] = mb_strtolower(str_replace('"', '', $thread));
        }
        if ($tubeMm !== null) {
            $aliases[] = $tubeMm . 'mm';
            $aliases[] = $tubeMm . ' mm';
            $aliases[] = $tubeMm;
        }

        return [
            'kind' => is_string($kind) ? $kind : (string) $series,
            'thread' => $thread,
            'size' => $sizeLabel,
            'aliases' => array_values(array_unique($aliases)),
        ];
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
                'chapter' => (int) ($chapter['chapter'] ?? 0),
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
                $this->compactKeys[str_replace(' ', '', $key)] = $key;
            }
        }
    }

    /**
     * @param  CatalogEntry  $entry
     */
    private function buildSearchHaystack(array $entry): string
    {
        $article = $entry['article'];
        $decoded = self::decodeArticle($article);
        $parts = [
            $article['article_number'],
            str_replace(' ', '', (string) $article['article_number']),
            $article['series'] ?? '',
            $article['family'] ?? '',
            $article['description'] ?? '',
            $entry['designation'] ?? '',
            $article['specs'] ?? '',
            $entry['chapter']['name'],
            $entry['chapter']['category'],
            $decoded['kind'],
            $decoded['thread'] ?? '',
            $decoded['size'] ?? '',
        ];
        foreach ($decoded['aliases'] as $alias) {
            $parts[] = $alias;
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /**
     * @return array{0: string, 1: list<string>}|null
     */
    private static function seriesProfile(string $series): ?array
    {
        if (isset(self::SERIES_KIND[$series])) {
            return self::SERIES_KIND[$series];
        }

        $best = null;
        $bestLength = 0;
        foreach (self::SERIES_KIND as $prefix => $profile) {
            if (str_starts_with($series, $prefix) && strlen($prefix) > $bestLength) {
                $best = $profile;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    private static function sizeToken(string $articleNumber, string $series): string
    {
        $rest = trim(preg_replace('/^' . preg_quote($series, '/') . '\s+/u', '', $articleNumber) ?? $articleNumber);

        return explode(' ', $rest)[0] ?? $rest;
    }

    private static function threadFromIqsToken(string $sizeToken, string $articleNumber, string $specs, string $series = ''): ?string
    {
        if (str_starts_with($series, 'IQS') && preg_match('/^M(\d)(\d+)$/u', $sizeToken, $matches) === 1) {
            return 'M ' . $matches[1];
        }
        foreach (self::IQS_THREAD_PREFIXES as $prefix => $fraction) {
            $prefix = (string) $prefix;
            if (str_starts_with($sizeToken, $prefix) && ctype_digit(substr($sizeToken, strlen($prefix)))) {
                $standard = str_contains(strtoupper($articleNumber . ' ' . $specs), 'NPT') ? 'NPT' : 'G';
                if (preg_match('/\b(R|G|NPT)\b/u', strtoupper($specs), $specThread) === 1) {
                    $standard = $specThread[1];
                } elseif (preg_match('/\b(R|G)\s*$/u', strtoupper($articleNumber), $suffix) === 1) {
                    $standard = $suffix[1];
                }

                return $standard . ' ' . $fraction;
            }
        }
        if (preg_match('/\b([GR]|NPT|M)\s*(\d+\/\d+|\d+(?:\.\d+)?)/u', $specs, $matches) === 1) {
            $value = $matches[2];
            if (str_contains($value, '/')) {
                $value .= '"';
            }

            return $matches[1] . ' ' . $value;
        }

        return null;
    }

    private static function tubeMmFromIqsToken(string $sizeToken, string $specs, string $articleNumber, string $series = ''): ?string
    {
        if (str_starts_with($series, 'IQS') && preg_match('/^M\d(\d+)$/u', $sizeToken, $matches) === 1) {
            return self::formatNumber((float) $matches[1]);
        }
        foreach (array_keys(self::IQS_THREAD_PREFIXES) as $prefix) {
            $prefix = (string) $prefix;
            if (str_starts_with($sizeToken, $prefix)) {
                $rest = substr($sizeToken, strlen($prefix));
                if ($rest !== '' && ctype_digit($rest)) {
                    return self::formatNumber((float) $rest);
                }
            }
        }
        if (preg_match('/^(\d+)(?:\s|[A-Z]|$)/u', $sizeToken, $matches) === 1
            && !isset(self::IQS_THREAD_PREFIXES[$matches[1]])
            && (int) $matches[1] >= 3
            && (int) $matches[1] <= 42
        ) {
            return self::formatNumber((float) $matches[1]);
        }
        if (preg_match('/["”]\s*(\d+(?:[.,]\d+)?)\b/u', $specs, $matches) === 1) {
            return self::formatNumber((float) str_replace(',', '.', $matches[1]));
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)["”](\d+)/u', $specs . $articleNumber, $matches) === 1) {
            return self::formatNumber((float) $matches[2]);
        }

        return null;
    }

    public static function formatNumber(int|float $value): string
    {
        $formatted = number_format((float) $value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Missing Landefeld catalog file: ' . $path);
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid Landefeld catalog file: ' . $path);
        }

        return $data;
    }
}

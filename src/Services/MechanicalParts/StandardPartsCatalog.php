<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2026 Part-DB contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace App\Services\MechanicalParts;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StandardPartsCatalog
{
    /** @var array<string, mixed>|null */
    private ?array $catalog = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/mechanical/bolts/catalog.json')]
        private readonly string $catalogPath,
    ) {
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     attributes: array<string, mixed>,
     *     source_url: string
     * }>
     */
    public function search(string $query, int $limit = 50): array
    {
        $query = str_replace('×', ' x ', $query);
        $query = preg_replace('/(?<=\d)x(?=\d)/i', ' x ', $query) ?? $query;
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9.]+/i', strtolower(trim($query))) ?: [],
            static fn (string $token): bool => $token !== '' && $token !== 'x'
        ));
        $results = [];

        foreach ($this->parts() as $part) {
            foreach ($this->variants($part) as $variant) {
                $haystack = strtolower(implode(' ', [
                    $part['id'],
                    $part['name'],
                    $part['description'],
                    $part['standard'],
                    implode(' ', $part['aliases'] ?? []),
                    $part['hardware_type'],
                    $variant['thread_designation'],
                    $variant['length'] ?? '',
                ]));

                $matches = true;
                foreach ($tokens as $token) {
                    if (!str_contains($haystack, $token)) {
                        $matches = false;
                        break;
                    }
                }
                if (!$matches) {
                    continue;
                }

                $results[] = $this->buildVariant($part, $variant);
                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     attributes: array<string, mixed>,
     *     source_url: string
     * }
     */
    public function get(string $id): array
    {
        $parts = explode(':', $id);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid standard part ID.');
        }

        [$partId, $thread, $lengthValue] = $parts;
        $part = null;
        foreach ($this->parts() as $candidate) {
            if ($candidate['id'] === $partId) {
                $part = $candidate;
                break;
            }
        }
        if ($part === null || !array_key_exists($thread, $part['sizes'])) {
            throw new \InvalidArgumentException(sprintf('Unknown standard part "%s".', $id));
        }

        $length = $lengthValue === '-' ? null : (float) $lengthValue;
        if ($length !== null && !in_array($length, $part['lengths'], false)) {
            throw new \InvalidArgumentException(sprintf('Unsupported length in standard part "%s".', $id));
        }

        return $this->buildVariant($part, [
            'thread_designation' => $thread,
            'thread_pitch' => (float) $part['sizes'][$thread],
            'length' => $length,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function source(): array
    {
        return $this->load()['source'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parts(): array
    {
        return $this->load()['parts'];
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $contents = @file_get_contents($this->catalogPath);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read standard parts catalog at "%s".', $this->catalogPath));
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['source'], $decoded['parts']) || !is_array($decoded['parts'])) {
            throw new \RuntimeException('The standard parts catalog has an invalid structure.');
        }

        return $this->catalog = $decoded;
    }

    /**
     * @param array<string, mixed> $part
     * @return iterable<array{thread_designation: string, thread_pitch: float, length: ?float}>
     */
    private function variants(array $part): iterable
    {
        foreach ($part['sizes'] as $thread => $pitch) {
            if ($part['lengths'] === []) {
                yield [
                    'thread_designation' => (string) $thread,
                    'thread_pitch' => (float) $pitch,
                    'length' => null,
                ];
                continue;
            }

            foreach ($part['lengths'] as $length) {
                yield [
                    'thread_designation' => (string) $thread,
                    'thread_pitch' => (float) $pitch,
                    'length' => (float) $length,
                ];
            }
        }
    }

    /**
     * @param array<string, mixed> $part
     * @param array{thread_designation: string, thread_pitch: float, length: ?float} $variant
     * @return array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     attributes: array<string, mixed>,
     *     source_url: string
     * }
     */
    private function buildVariant(array $part, array $variant): array
    {
        $size = $variant['thread_designation'];
        if ($variant['length'] !== null) {
            $size .= ' x '.$this->formatNumber($variant['length']);
        }

        return [
            'id' => implode(':', [
                $part['id'],
                $variant['thread_designation'],
                $variant['length'] !== null ? $this->formatNumber($variant['length']) : '-',
            ]),
            'name' => sprintf('%s %s %s', $part['standard'], $part['name'], $size),
            'description' => $part['description'],
            'attributes' => [
                'standard' => $part['standard'],
                'hardware_type' => $part['hardware_type'],
                'head_style' => $part['head_style'] ?? null,
                'drive' => $part['drive'] ?? null,
                'thread_system' => 'Metric',
                'thread_designation' => $variant['thread_designation'],
                'thread_pitch' => $variant['thread_pitch'],
                'length' => $variant['length'],
                'source_collection' => 'BOLTS',
            ],
            'source_url' => 'https://boltsparts.github.io/',
        ];
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}

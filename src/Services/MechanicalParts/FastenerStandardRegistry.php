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

/**
 * Maps commonly encountered standard aliases to one canonical mechanical
 * classification. It intentionally contains classification metadata rather
 * than copyrighted standard text.
 */
final class FastenerStandardRegistry
{
    private const BASE = 'Mechanical -> Fasteners';

    /**
     * @var array<string, array{
     *     canonical: string,
     *     equivalents: string[],
     *     type: string,
     *     category: string,
     *     head?: string,
     *     drive?: string
     * }>
     */
    private const RULES = [
        'ISO 4762' => [
            'canonical' => 'ISO 4762',
            'equivalents' => ['DIN 912'],
            'type' => 'Socket head cap screw',
            'category' => self::BASE.' -> Bolts & Screws -> Socket Head Cap Screws',
            'head' => 'Socket head cap',
            'drive' => 'Internal hex',
        ],
        'ISO 10642' => [
            'canonical' => 'ISO 10642',
            'equivalents' => ['DIN 7991'],
            'type' => 'Countersunk socket screw',
            'category' => self::BASE.' -> Bolts & Screws -> Countersunk Screws',
            'head' => 'Countersunk',
            'drive' => 'Internal hex',
        ],
        'ISO 4017' => [
            'canonical' => 'ISO 4017',
            'equivalents' => ['DIN 933'],
            'type' => 'Hexagon head screw',
            'category' => self::BASE.' -> Bolts & Screws -> Hex Head Screws',
            'head' => 'Hexagon',
            'drive' => 'External hex',
        ],
        'ISO 4014' => [
            'canonical' => 'ISO 4014',
            'equivalents' => ['DIN 931'],
            'type' => 'Hexagon head bolt',
            'category' => self::BASE.' -> Bolts & Screws -> Hex Head Bolts',
            'head' => 'Hexagon',
            'drive' => 'External hex',
        ],
        'ISO 7380-1' => [
            'canonical' => 'ISO 7380-1',
            'equivalents' => [],
            'type' => 'Button head socket screw',
            'category' => self::BASE.' -> Bolts & Screws -> Button Head Screws',
            'head' => 'Button',
            'drive' => 'Internal hex',
        ],
        'ISO 4026' => [
            'canonical' => 'ISO 4026',
            'equivalents' => ['DIN 913'],
            'type' => 'Flat point set screw',
            'category' => self::BASE.' -> Bolts & Screws -> Set Screws',
            'head' => 'Headless',
            'drive' => 'Internal hex',
        ],
        'ISO 4027' => [
            'canonical' => 'ISO 4027',
            'equivalents' => ['DIN 914'],
            'type' => 'Cone point set screw',
            'category' => self::BASE.' -> Bolts & Screws -> Set Screws',
            'head' => 'Headless',
            'drive' => 'Internal hex',
        ],
        'ISO 4028' => [
            'canonical' => 'ISO 4028',
            'equivalents' => ['DIN 915'],
            'type' => 'Dog point set screw',
            'category' => self::BASE.' -> Bolts & Screws -> Set Screws',
            'head' => 'Headless',
            'drive' => 'Internal hex',
        ],
        'ISO 4029' => [
            'canonical' => 'ISO 4029',
            'equivalents' => ['DIN 916'],
            'type' => 'Cup point set screw',
            'category' => self::BASE.' -> Bolts & Screws -> Set Screws',
            'head' => 'Headless',
            'drive' => 'Internal hex',
        ],
        'ISO 4032' => [
            'canonical' => 'ISO 4032',
            'equivalents' => ['DIN 934'],
            'type' => 'Hexagon nut',
            'category' => self::BASE.' -> Nuts -> Hex Nuts',
            'drive' => 'External hex',
        ],
        'ISO 4035' => [
            'canonical' => 'ISO 4035',
            'equivalents' => ['DIN 439'],
            'type' => 'Thin hexagon nut',
            'category' => self::BASE.' -> Nuts -> Thin Nuts',
            'drive' => 'External hex',
        ],
        'ISO 7089' => [
            'canonical' => 'ISO 7089',
            'equivalents' => ['DIN 125 A'],
            'type' => 'Plain washer',
            'category' => self::BASE.' -> Washers -> Plain Washers',
        ],
        'ISO 7090' => [
            'canonical' => 'ISO 7090',
            'equivalents' => ['DIN 125 B'],
            'type' => 'Chamfered plain washer',
            'category' => self::BASE.' -> Washers -> Plain Washers',
        ],
        'DIN 603' => [
            'canonical' => 'DIN 603',
            'equivalents' => ['ISO 8677'],
            'type' => 'Carriage bolt',
            'category' => self::BASE.' -> Bolts & Screws -> Carriage Bolts',
            'head' => 'Round',
            'drive' => 'Square neck',
        ],
        'DIN 571' => [
            'canonical' => 'DIN 571',
            'equivalents' => [],
            'type' => 'Hexagon head wood screw',
            'category' => self::BASE.' -> Bolts & Screws -> Wood Screws',
            'head' => 'Hexagon',
            'drive' => 'External hex',
        ],
    ];

    /**
     * @return array{canonical: string, equivalents: string[], type: string, category: string, head?: string, drive?: string}|null
     */
    public function resolve(?string $standard): ?array
    {
        if ($standard === null || trim($standard) === '') {
            return null;
        }

        $normalized = $this->normalize($standard);
        foreach (self::RULES as $rule) {
            $names = [$rule['canonical'], ...$rule['equivalents']];
            foreach ($names as $name) {
                if ($this->normalize($name) === $normalized) {
                    return $rule;
                }
            }
        }

        return null;
    }

    public function canonicalize(string $standard): string
    {
        return $this->resolve($standard)['canonical'] ?? trim(preg_replace('/\s+/', ' ', strtoupper($standard)) ?? $standard);
    }

    /**
     * @return string[]
     */
    public function equivalentStandards(string $standard): array
    {
        $rule = $this->resolve($standard);
        if ($rule === null) {
            return [];
        }

        return $rule['equivalents'];
    }

    private function normalize(string $standard): string
    {
        $standard = strtoupper(trim($standard));
        $standard = preg_replace('/^(?:DIN\s*EN\s*)?ISO\s*/', 'ISO ', $standard) ?? $standard;
        $standard = preg_replace('/^DINISO\s*/', 'ISO ', $standard) ?? $standard;
        $standard = preg_replace('/\s+/', ' ', $standard) ?? $standard;

        return str_replace(['–', '—'], '-', $standard);
    }
}

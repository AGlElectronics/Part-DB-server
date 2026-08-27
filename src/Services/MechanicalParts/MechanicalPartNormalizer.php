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

use App\Services\InfoProviderSystem\DTOs\ParameterDTO;

final class MechanicalPartNormalizer
{
    private const PARAMETER_GROUP = 'Mechanical parameters';

    public function __construct(private readonly FastenerStandardRegistry $standardRegistry)
    {
    }

    /**
     * Normalize attributes from a supplier or standards catalog.
     *
     * Accepted aliases make adapters small while the emitted Part-DB parameter
     * names remain stable.
     *
     * @param array<string, mixed> $attributes
     */
    public function normalize(array $attributes): MechanicalPartDefinition
    {
        $standard = $this->stringValue($attributes, ['standard', 'norm', 'din_iso', 'standard_name']);
        $rule = $this->standardRegistry->resolve($standard);

        $threadDesignation = $this->stringValue($attributes, ['thread_designation', 'thread', 'thread_size', 'key']);
        $length = $this->floatValue($attributes, ['length', 'length_mm', 'l']);

        $designation = $this->stringValue($attributes, ['designation', 'size', 'name']);
        if ($designation !== null) {
            $parsed = $this->parseMetricDesignation($designation);
            $threadDesignation ??= $parsed['thread'];
            $length ??= $parsed['length'];
        }

        $diameter = $this->floatValue($attributes, ['nominal_diameter', 'diameter', 'diameter_mm', 'd', 'd1']);
        if ($diameter === null && $threadDesignation !== null
            && preg_match('/^M\s*(\d+(?:[.,]\d+)?)/i', $threadDesignation, $matches) === 1) {
            $diameter = (float) str_replace(',', '.', $matches[1]);
        }

        $pitch = $this->floatValue($attributes, ['thread_pitch', 'pitch', 'pitch_mm']);
        if ($pitch === null && $threadDesignation !== null
            && preg_match('/^M\s*\d+(?:[.,]\d+)?\s*[x×]\s*(\d+(?:[.,]\d+)?)/i', $threadDesignation, $matches) === 1) {
            $pitch = (float) str_replace(',', '.', $matches[1]);
        }

        $hardwareType = $this->stringValue($attributes, ['hardware_type', 'part_type', 'type'])
            ?? $rule['type']
            ?? 'Mechanical part';
        $categoryPath = $this->stringValue($attributes, ['category_path', 'category'])
            ?? $rule['category']
            ?? $this->fallbackCategory($hardwareType);

        $knownKeys = [
            'standard', 'norm', 'din_iso', 'standard_name', 'thread_designation', 'thread', 'thread_size', 'key',
            'length', 'length_mm', 'l', 'designation', 'size', 'name', 'nominal_diameter', 'diameter', 'diameter_mm',
            'd', 'd1', 'thread_pitch', 'pitch', 'pitch_mm', 'hardware_type', 'part_type', 'type', 'category_path',
            'category', 'head_style', 'head', 'drive', 'drive_style', 'thread_system', 'material', 'finish',
            'coating', 'property_class', 'strength_class', 'grade', 'hardness',
        ];
        $additional = [];
        foreach ($attributes as $key => $value) {
            if (!in_array($key, $knownKeys, true) && (is_string($value) || is_int($value) || is_float($value))) {
                $additional[$this->humanize((string) $key)] = $value;
            }
        }

        return new MechanicalPartDefinition(
            hardwareType: $hardwareType,
            categoryPath: $categoryPath,
            standard: $standard !== null ? $this->standardRegistry->canonicalize($standard) : null,
            equivalentStandards: $standard !== null ? $this->standardRegistry->equivalentStandards($standard) : [],
            headStyle: $this->stringValue($attributes, ['head_style', 'head']) ?? ($rule['head'] ?? null),
            drive: $this->stringValue($attributes, ['drive', 'drive_style']) ?? ($rule['drive'] ?? null),
            threadSystem: $this->stringValue($attributes, ['thread_system'])
                ?? ($threadDesignation !== null && str_starts_with(strtoupper($threadDesignation), 'M') ? 'Metric' : null),
            threadDesignation: $threadDesignation,
            nominalDiameter: $diameter,
            threadPitch: $pitch,
            length: $length,
            material: $this->stringValue($attributes, ['material']),
            finish: $this->stringValue($attributes, ['finish', 'coating']),
            propertyClass: $this->stringValue($attributes, ['property_class', 'strength_class', 'grade']),
            hardness: $this->stringValue($attributes, ['hardness']),
            additionalProperties: $additional,
        );
    }

    /**
     * @return ParameterDTO[]
     */
    public function toParameters(MechanicalPartDefinition $part): array
    {
        $parameters = [];
        $this->addText($parameters, 'Hardware type', $part->hardwareType);
        $this->addText($parameters, 'Standard', $part->standard);
        $this->addText($parameters, 'Equivalent standards',
            $part->equivalentStandards !== [] ? implode(', ', $part->equivalentStandards) : null);
        $this->addText($parameters, 'Head style', $part->headStyle);
        $this->addText($parameters, 'Drive', $part->drive);
        $this->addText($parameters, 'Thread system', $part->threadSystem);
        $this->addText($parameters, 'Thread designation', $part->threadDesignation, 'd');
        $this->addNumber($parameters, 'Nominal diameter', $part->nominalDiameter, 'mm', 'd');
        $this->addNumber($parameters, 'Thread pitch', $part->threadPitch, 'mm', 'P');
        $this->addNumber($parameters, 'Length', $part->length, 'mm', 'l');
        $this->addText($parameters, 'Material', $part->material);
        $this->addText($parameters, 'Finish', $part->finish);
        $this->addText($parameters, 'Property class', $part->propertyClass);
        $this->addText($parameters, 'Hardness', $part->hardness);

        foreach ($part->additionalProperties as $name => $value) {
            if (is_float($value) || is_int($value)) {
                $this->addNumber($parameters, $name, (float) $value);
            } else {
                $this->addText($parameters, $name, $value);
            }
        }

        return $parameters;
    }

    /**
     * @return array{thread: ?string, length: ?float}
     */
    public function parseMetricDesignation(string $designation): array
    {
        $designation = str_replace(['×', ','], ['x', '.'], $designation);
        if (preg_match('/\b(M\s*\d+(?:\.\d+)?(?:\s*x\s*\d+(?:\.\d+)?)?)\s*x\s*(\d+(?:\.\d+)?)\b/i',
            $designation, $matches) !== 1) {
            return ['thread' => null, 'length' => null];
        }

        return [
            'thread' => strtoupper((string) preg_replace('/\s+/', '', $matches[1])),
            'length' => (float) $matches[2],
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param string[] $keys
     */
    private function stringValue(array $attributes, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($attributes[$key]) && (is_string($attributes[$key]) || is_numeric($attributes[$key]))) {
                $value = trim((string) $attributes[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param string[] $keys
     */
    private function floatValue(array $attributes, array $keys): ?float
    {
        $value = $this->stringValue($attributes, $keys);
        if ($value === null) {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        return (float) $matches[0];
    }

    private function fallbackCategory(string $hardwareType): string
    {
        $type = strtolower($hardwareType);

        return match (true) {
            str_contains($type, 'nut') => 'Mechanical -> Fasteners -> Nuts',
            str_contains($type, 'washer') => 'Mechanical -> Fasteners -> Washers',
            str_contains($type, 'bearing') => 'Mechanical -> Bearings',
            str_contains($type, 'profile'), str_contains($type, 'beam') => 'Mechanical -> Profiles',
            str_contains($type, 'pipe'), str_contains($type, 'tube') => 'Mechanical -> Pipes & Tubes',
            default => 'Mechanical -> Fasteners -> Bolts & Screws',
        };
    }

    private function humanize(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * @param ParameterDTO[] $parameters
     */
    private function addText(array &$parameters, string $name, ?string $value, ?string $symbol = null): void
    {
        if ($value !== null && $value !== '') {
            $parameters[] = new ParameterDTO(
                name: $name,
                value_text: $value,
                symbol: $symbol,
                group: self::PARAMETER_GROUP
            );
        }
    }

    /**
     * @param ParameterDTO[] $parameters
     */
    private function addNumber(array &$parameters, string $name, ?float $value, ?string $unit = null, ?string $symbol = null): void
    {
        if ($value !== null) {
            $parameters[] = new ParameterDTO(
                name: $name,
                value_typ: $value,
                unit: $unit,
                symbol: $symbol,
                group: self::PARAMETER_GROUP
            );
        }
    }
}

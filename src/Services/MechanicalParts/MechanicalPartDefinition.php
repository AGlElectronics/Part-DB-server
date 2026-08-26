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
 * Supplier-neutral representation of the mechanical properties of a part.
 *
 * Property class (for example 12.9) and measured hardness (for example
 * 39 HRC) are deliberately separate because they are not interchangeable.
 */
readonly class MechanicalPartDefinition
{
    /**
     * @param string[] $equivalentStandards
     * @param array<string, string|float|int> $additionalProperties
     */
    public function __construct(
        public string $hardwareType,
        public string $categoryPath,
        public ?string $standard = null,
        public array $equivalentStandards = [],
        public ?string $headStyle = null,
        public ?string $drive = null,
        public ?string $threadSystem = null,
        public ?string $threadDesignation = null,
        public ?float $nominalDiameter = null,
        public ?float $threadPitch = null,
        public ?float $length = null,
        public ?string $material = null,
        public ?string $finish = null,
        public ?string $propertyClass = null,
        public ?string $hardness = null,
        public array $additionalProperties = [],
    ) {
    }
}

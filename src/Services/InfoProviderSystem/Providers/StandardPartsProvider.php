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

namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\ProviderInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Services\MechanicalParts\MechanicalPartNormalizer;
use App\Services\MechanicalParts\StandardPartsCatalog;

/**
 * Offline provider for standardized mechanical parts. It behaves like a
 * distributor provider, but uses the bundled BOLTS metadata and never needs
 * network access or credentials.
 */
final class StandardPartsProvider implements InfoProviderInterface
{
    public const PROVIDER_KEY = 'standard_parts';

    public function __construct(
        private readonly StandardPartsCatalog $catalog,
        private readonly MechanicalPartNormalizer $normalizer,
    ) {
    }

    public function getProviderInfo(): ProviderInfoDTO
    {
        return new ProviderInfoDTO(
            key: self::PROVIDER_KEY,
            name: 'Standard mechanical parts',
            description: 'Search the bundled BOLTS mechanical standards catalog.',
            url: 'https://boltsparts.github.io/',
            capabilities: [
                ProviderCapabilities::BASIC,
                ProviderCapabilities::PARAMETERS,
            ],
        );
    }

    public function isActive(): bool
    {
        return true;
    }

    public function searchByKeyword(string $keyword, array $options = []): array
    {
        return array_map(
            fn (array $variant): SearchResultDTO => $this->toSearchResult($variant),
            $this->catalog->search($keyword)
        );
    }

    public function getDetails(string $id, array $options = []): PartDetailDTO
    {
        $variant = $this->catalog->get($id);
        $definition = $this->normalizer->normalize($variant['attributes']);

        return new PartDetailDTO(
            provider_key: self::PROVIDER_KEY,
            provider_id: $variant['id'],
            name: $variant['name'],
            description: $variant['description'],
            category: $definition->categoryPath,
            provider_url: $variant['source_url'],
            parameters: $this->normalizer->toParameters($definition),
            notes: sprintf(
                'Definition metadata from BOLTS revision %s. Verify requirements against the official standard.',
                $this->catalog->source()['revision']
            ),
        );
    }

    /**
     * @param array{id: string, name: string, description: string, attributes: array<string, mixed>, source_url: string} $variant
     */
    private function toSearchResult(array $variant): SearchResultDTO
    {
        $definition = $this->normalizer->normalize($variant['attributes']);

        return new SearchResultDTO(
            provider_key: self::PROVIDER_KEY,
            provider_id: $variant['id'],
            name: $variant['name'],
            description: $variant['description'],
            category: $definition->categoryPath,
            provider_url: $variant['source_url'],
        );
    }
}

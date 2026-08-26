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

use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\ProviderInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Services\MechanicalParts\MechanicalPartNormalizer;
use App\Settings\InfoProviderSystem\TracePartsSettings;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Exact-part-number lookup against the TraceParts API.
 *
 * TraceParts does not expose a general keyword-search endpoint. The provider
 * therefore uses its documented part-number availability endpoint and then
 * loads the selected configuration. CAD generation is intentionally not
 * triggered because that flow requires downloader identity information.
 */
final class TracePartsProvider implements InfoProviderInterface
{
    public const PROVIDER_KEY = 'traceparts';
    public const BASE_URL = 'https://api-gateway.traceparts.com';

    private const TOKEN_CACHE_KEY = 'traceparts_api_token';

    public function __construct(
        private readonly TracePartsSettings $settings,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $partInfoCache,
        private readonly MechanicalPartNormalizer $normalizer,
    ) {
    }

    public function getProviderInfo(): ProviderInfoDTO
    {
        return new ProviderInfoDTO(
            key: self::PROVIDER_KEY,
            name: 'TraceParts',
            description: 'Looks up manufacturer parts and mechanical metadata using the TraceParts API.',
            url: 'https://www.traceparts.com/',
            disabledHelp: 'Configure an API key, tenant UID, catalog label, and confirm catalog syndication approval.',
            settingsClass: TracePartsSettings::class,
            capabilities: [
                ProviderCapabilities::BASIC,
                ProviderCapabilities::PICTURE,
                ProviderCapabilities::DATASHEET,
                ProviderCapabilities::PARAMETERS,
            ],
            expensive: true,
        );
    }

    public function isActive(): bool
    {
        return $this->settings->syndicationApproved
            && $this->notEmpty($this->settings->apiKey)
            && $this->notEmpty($this->settings->tenantUid)
            && $this->notEmpty($this->settings->catalog);
    }

    public function searchByKeyword(string $keyword, array $options = []): array
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/v2/Search/PartNumber/Availability', [
                'auth_bearer' => $this->getToken(),
                'query' => [
                    'partNumber' => trim($keyword),
                    'catalog' => $this->settings->catalog,
                    'removeChar' => true,
                ],
            ]);
            $result = $response->toArray();
        } catch (ClientExceptionInterface $exception) {
            if ($exception->getResponse()->getStatusCode() === 404) {
                return [];
            }
            throw $exception;
        }

        if (!$this->notEmpty($result['partFamilyCode'] ?? null)
            || !$this->notEmpty($result['classificationCode'] ?? null)
            || !$this->notEmpty($result['partNumber'] ?? null)) {
            return [];
        }

        $id = $this->encodeID($result);

        return [new SearchResultDTO(
            provider_key: self::PROVIDER_KEY,
            provider_id: $id,
            name: (string) $result['partNumber'],
            description: sprintf('TraceParts result from catalog %s', $this->settings->catalog),
            manufacturer: $this->settings->catalog,
            mpn: (string) $result['partNumber'],
            provider_url: $this->tracePartsURL($result),
        )];
    }

    public function getDetails(string $id, array $options = []): PartDetailDTO
    {
        $reference = $this->decodeID($id);
        $response = $this->httpClient->request('GET', self::BASE_URL.'/v3/Product/Configure', [
            'auth_bearer' => $this->getToken(),
            'query' => array_filter([
                'partFamilyCode' => $reference['partFamilyCode'],
                'cultureInfo' => $this->settings->language,
                'selectionPath' => $reference['selectionPath'],
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
        $data = $response->toArray();

        $partInfo = $data['globalInfo']['partFamilyInfo'] ?? [];
        $rawAttributes = $this->bomToAttributes($data['bomFields'] ?? []);
        if (!isset($rawAttributes['hardware_type']) && $this->notEmpty($partInfo['title'] ?? null)) {
            $rawAttributes['hardware_type'] = (string) $partInfo['title'];
        }
        if ($this->notEmpty($partInfo['title'] ?? null)) {
            $rawAttributes['designation'] = (string) $partInfo['title'];
        }
        $definition = $this->normalizer->normalize($rawAttributes);
        $documents = $this->documentsToDTOs($data['documentList'] ?? []);
        $images = $this->imagesToDTOs($partInfo);
        $productURL = $this->productURLFromLinks($data['currentConfigurationLinkList']['links'] ?? [])
            ?? $this->tracePartsURL($reference);

        $description = (string) ($partInfo['longDescription'] ?? $partInfo['description'] ?? '');
        $name = (string) ($partInfo['title'] ?? $reference['partNumber']);

        return new PartDetailDTO(
            provider_key: self::PROVIDER_KEY,
            provider_id: $id,
            name: $name,
            description: $description,
            category: $definition->categoryPath,
            manufacturer: (string) ($partInfo['classificationLabel'] ?? $this->settings->catalog),
            mpn: $reference['partNumber'],
            preview_image_url: $partInfo['partNumberPictureUrl'] ?? $partInfo['partFamilyPictureUrl'] ?? null,
            provider_url: $productURL,
            notes: $this->cadFormatsNote($data['cadFormatList'] ?? []),
            datasheets: $documents,
            images: $images,
            parameters: $this->normalizer->toParameters($definition),
            manufacturer_product_url: $productURL,
        );
    }

    private function getToken(): string
    {
        $item = $this->partInfoCache->getItem(self::TOKEN_CACHE_KEY);
        if ($item->isHit() && is_string($item->get()) && $item->get() !== '') {
            return $item->get();
        }

        $response = $this->httpClient->request('POST', self::BASE_URL.'/v2/RequestToken', [
            'json' => [
                'tenantUid' => $this->settings->tenantUid,
                'apiKey' => $this->settings->apiKey,
            ],
        ]);
        $data = $response->toArray();
        if (!$this->notEmpty($data['token'] ?? null)) {
            throw new \RuntimeException('TraceParts token response did not contain a token.');
        }

        $ttl = 3600;
        if ($this->notEmpty($data['expiryDate'] ?? null)) {
            try {
                $ttl = max(60, (new \DateTimeImmutable((string) $data['expiryDate']))->getTimestamp() - time() - 60);
            } catch (\Exception) {
                // Keep the conservative one-hour fallback.
            }
        }
        $item->set((string) $data['token']);
        $item->expiresAfter($ttl);
        $this->partInfoCache->save($item);

        return (string) $data['token'];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    private function bomToAttributes(array $fields): array
    {
        $attributes = [];
        foreach ($fields as $field) {
            $label = trim((string) ($field['label'] ?? $field['symbol'] ?? ''));
            $value = $field['value'] ?? null;
            if ($label === '' || (!is_string($value) && !is_numeric($value))) {
                continue;
            }

            $key = $this->mechanicalKey($label);
            $unit = trim((string) ($field['unit'] ?? ''));
            $attributes[$key] = $unit !== '' ? trim((string) $value).' '.$unit : $value;
        }

        return $attributes;
    }

    private function mechanicalKey(string $label): string
    {
        $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $label) ?? $label));

        return match ($normalized) {
            'standard', 'norm', 'din iso', 'standard name' => 'standard',
            'thread', 'thread size', 'thread designation' => 'thread_designation',
            'diameter', 'nominal diameter', 'shaft diameter' => 'nominal_diameter',
            'pitch', 'thread pitch' => 'thread_pitch',
            'length', 'shaft length', 'total length' => 'length',
            'head', 'head style', 'head type' => 'head_style',
            'drive', 'drive style', 'drive type' => 'drive',
            'material' => 'material',
            'finish', 'coating', 'surface finish' => 'finish',
            'property class', 'strength class', 'grade' => 'property_class',
            'hardness' => 'hardness',
            'type', 'part type', 'hardware type' => 'hardware_type',
            default => str_replace(' ', '_', $normalized),
        };
    }

    /**
     * @param array<string, mixed> $documentList
     * @return FileDTO[]
     */
    private function documentsToDTOs(array $documentList): array
    {
        $documents = [
            ...($documentList['partFamilyDocumentList'] ?? []),
            ...($documentList['technicalDocumentList'] ?? []),
        ];
        $result = [];
        foreach ($documents as $document) {
            if ($this->notEmpty($document['url'] ?? null)) {
                $result[] = new FileDTO((string) $document['url'], $document['title'] ?? null);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $partInfo
     * @return FileDTO[]
     */
    private function imagesToDTOs(array $partInfo): array
    {
        $urls = array_unique(array_filter([
            $partInfo['partNumberPictureUrl'] ?? null,
            $partInfo['partFamilyPictureUrl'] ?? null,
        ], fn (mixed $url): bool => $this->notEmpty($url)));

        return array_map(static fn (string $url): FileDTO => new FileDTO($url), $urls);
    }

    /**
     * @param array<int, array<string, mixed>> $links
     */
    private function productURLFromLinks(array $links): ?string
    {
        foreach ($links as $link) {
            if (in_array(strtolower((string) ($link['type'] ?? '')), ['productpage', 'eshop', 'webpage'], true)
                && $this->notEmpty($link['value'] ?? null)) {
                return (string) $link['value'];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $formats
     */
    private function cadFormatsNote(array $formats): ?string
    {
        $names = array_values(array_filter(array_map(
            static fn (array $format): ?string => isset($format['cadFormatName']) ? (string) $format['cadFormatName'] : null,
            $formats
        )));
        if ($names === []) {
            return 'Powered by TraceParts. CAD generation was not requested.';
        }

        return 'Powered by TraceParts. Available CAD formats: '.implode(', ', $names)
            .'. CAD generation was not requested.';
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function encodeID(array $reference): string
    {
        $json = json_encode([
            'classificationCode' => (string) $reference['classificationCode'],
            'partFamilyCode' => (string) $reference['partFamilyCode'],
            'partNumber' => (string) $reference['partNumber'],
            'selectionPath' => isset($reference['selectionPath']) ? (string) $reference['selectionPath'] : null,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{classificationCode: string, partFamilyCode: string, partNumber: string, selectionPath: ?string}
     */
    private function decodeID(string $id): array
    {
        $decoded = base64_decode(strtr($id, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid TraceParts provider ID.');
        }

        try {
            $reference = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid TraceParts provider ID.', previous: $exception);
        }
        foreach (['classificationCode', 'partFamilyCode', 'partNumber'] as $required) {
            if (!is_array($reference) || !$this->notEmpty($reference[$required] ?? null)) {
                throw new \InvalidArgumentException('Invalid TraceParts provider ID.');
            }
        }

        return [
            'classificationCode' => (string) $reference['classificationCode'],
            'partFamilyCode' => (string) $reference['partFamilyCode'],
            'partNumber' => (string) $reference['partNumber'],
            'selectionPath' => isset($reference['selectionPath']) ? (string) $reference['selectionPath'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function tracePartsURL(array $reference): string
    {
        return 'https://www.traceparts.com/en/product/?'.http_build_query([
            'Product' => $reference['partFamilyCode'] ?? null,
            'SelectionPath' => $reference['selectionPath'] ?? null,
            'SupplierID' => $reference['classificationCode'] ?? null,
            'PartNumber' => $reference['partNumber'] ?? null,
        ]);
    }

    private function notEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

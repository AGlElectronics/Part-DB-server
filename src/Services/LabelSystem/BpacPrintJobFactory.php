<?php

declare(strict_types=1);

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

namespace App\Services\LabelSystem;

use App\Entity\Base\AbstractDBElement;
use App\Entity\Contracts\NamedElementInterface;
use App\Entity\Parts\Part;
use App\Services\LabelSystem\Barcodes\BarcodeContentGenerator;

/**
 * Builds the JSON job consumed by the local Part-DB P-touch / b-PAC bridge.
 */
final class BpacPrintJobFactory
{
    public const PROTOCOL = 'partdb-bpac';
    public const MAX_PROTOCOL_LENGTH = 14000;

    public function __construct(
        private readonly BarcodeContentGenerator $barcodeContentGenerator,
    ) {
    }

    /**
     * @param object[] $elements
     *
     * @return array{version: int, width_mm: float, height_mm: float, copies: int, labels: list<array{id: string, name: string, description: string, qr_url: string}>}
     */
    public function create(array $elements, float $widthMm, float $heightMm, int $copies = 1): array
    {
        $labels = [];
        foreach ($elements as $element) {
            if (!$element instanceof AbstractDBElement) {
                continue;
            }

            $labels[] = [
                'id' => (string) ($element->getID() ?? 0),
                'name' => $element instanceof NamedElementInterface ? (string) $element->getName() : '',
                'description' => $this->plainDescription($element),
                'qr_url' => $this->barcodeContentGenerator->getURLContent($element),
            ];
        }

        return [
            'version' => 1,
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'copies' => max(1, $copies),
            'labels' => $labels,
        ];
    }

    /**
     * @param array<string, mixed> $job
     */
    public function toJson(array $job): string
    {
        return json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function toProtocolUrl(array $job): string
    {
        $encoded = rtrim(strtr(base64_encode($this->toJson($job)), '+/', '-_'), '=');

        return self::PROTOCOL.':print,'.$encoded;
    }

    /**
     * @param array<string, mixed> $job
     */
    public function fitsProtocol(array $job): bool
    {
        return strlen($this->toProtocolUrl($job)) <= self::MAX_PROTOCOL_LENGTH;
    }

    private function plainDescription(object $element): string
    {
        if (!$element instanceof Part) {
            return '';
        }

        $description = html_entity_decode(strip_tags((string) $element->getDescription()), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
    }
}

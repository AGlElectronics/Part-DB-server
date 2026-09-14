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
 * Builds a UTF-8 CSV that P-touch Editor can connect as a database for bulk printing.
 */
final class PtouchCsvExporter
{
    public const HEADERS = ['id', 'name', 'description', 'qr_url'];

    public function __construct(
        private readonly BarcodeContentGenerator $barcodeContentGenerator,
    ) {
    }

    /**
     * @param object[] $elements
     */
    public function export(array $elements): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary CSV stream.');
        }

        fputcsv($handle, self::HEADERS, ',', '"', '\\');

        foreach ($elements as $element) {
            if (!$element instanceof AbstractDBElement) {
                continue;
            }

            fputcsv($handle, [
                (string) ($element->getID() ?? 0),
                $element instanceof NamedElementInterface ? $element->getName() : '',
                $element instanceof Part ? $element->getDescription() : '',
                $this->barcodeContentGenerator->getURLContent($element),
            ], ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new \RuntimeException('Could not read the P-touch CSV.');
        }

        return $csv;
    }
}

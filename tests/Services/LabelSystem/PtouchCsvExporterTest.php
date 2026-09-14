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

namespace App\Tests\Services\LabelSystem;

use App\Entity\Parts\Part;
use App\Services\LabelSystem\PtouchCsvExporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PtouchCsvExporterTest extends KernelTestCase
{
    public function testExportContainsHeadersNameAndQrUrl(): void
    {
        self::bootKernel();
        $exporter = self::getContainer()->get(PtouchCsvExporter::class);

        $part = new Part();
        $part->setName('DIN 912 M6 x 20');
        $part->setDescription('Socket head cap screw');

        $csv = $exporter->export([$part]);

        $this->assertStringContainsString('id,name,description,qr_url', $csv);
        $this->assertStringContainsString('DIN 912 M6 x 20', $csv);
        $this->assertStringContainsString('Socket head cap screw', $csv);
        $this->assertStringContainsString('/scan/part/', $csv);
        $this->assertStringContainsString('partdb.changeme.invalid', $csv);
    }
}

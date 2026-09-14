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
use App\Services\LabelSystem\BpacPrintJobFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BpacPrintJobFactoryTest extends KernelTestCase
{
    public function testCreateIncludesSizeNameAndQrUrl(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(BpacPrintJobFactory::class);

        $part = new Part();
        $part->setName('DIN 912 M6 x 20');
        $part->setDescription('Hexagon <b>socket</b> head cap screw');

        $job = $factory->create([$part], 30.0, 18.0);

        $this->assertSame(1, $job['version']);
        $this->assertSame(30.0, $job['width_mm']);
        $this->assertSame(18.0, $job['height_mm']);
        $this->assertSame(1, $job['copies']);
        $this->assertCount(1, $job['labels']);
        $this->assertSame('DIN 912 M6 x 20', $job['labels'][0]['name']);
        $this->assertSame('Hexagon socket head cap screw', $job['labels'][0]['description']);
        $this->assertStringContainsString('/scan/part/', $job['labels'][0]['qr_url']);

        $protocol = $factory->toProtocolUrl($job);
        $this->assertStringStartsWith('partdb-bpac:print,', $protocol);
        $this->assertTrue($factory->fitsProtocol($job));
    }
}

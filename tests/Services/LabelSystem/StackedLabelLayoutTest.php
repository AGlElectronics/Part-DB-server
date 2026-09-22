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

use App\Command\InstallP700LabelProfilesCommand;
use App\Entity\LabelSystem\BarcodeType;
use App\Entity\LabelSystem\LabelOptions;
use App\Entity\LabelSystem\LabelProcessMode;
use App\Entity\LabelSystem\LabelSupportedElement;
use App\Entity\Parts\Part;
use App\Entity\Parts\StorageLocation;
use App\Services\LabelSystem\LabelHTMLGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StackedLabelLayoutTest extends KernelTestCase
{
    private ?LabelHTMLGenerator $service = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(LabelHTMLGenerator::class);
    }

    public function testStackedLayoutIsUsedWhenMarkerIsPresent(): void
    {
        $html = $this->service->getLabelHTML($this->options(withStackedMarker: true), [$this->part()]);

        $this->assertStringContainsString('stacked-label', $html);
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('stacked-name', $html);
        $this->assertStringContainsString('DIN 912 M6 x 20', $html);
        $this->assertStringContainsString('Socket head cap screw', $html);
        $this->assertStringNotContainsString('col-5', $html);
        $this->assertStringNotContainsString('beside-label', $html);
    }

    public function testBesideLayoutPutsQrAndNameOnOneRow(): void
    {
        $options = new LabelOptions();
        $options->setSupportedElement(LabelSupportedElement::STORELOCATION);
        $options->setBarcodeType(BarcodeType::QR);
        $options->setProcessMode(LabelProcessMode::PLACEHOLDER);
        $options->setWidth(70.0);
        $options->setHeight(12.0);
        $options->setLines(InstallP700LabelProfilesCommand::BESIDE_LINES);
        $options->setAdditionalCss('/* '.InstallP700LabelProfilesCommand::BESIDE_MARKER.' */');

        $location = new StorageLocation();
        $location->setName('Box A3');

        $html = $this->service->getLabelHTML($options, [$location]);

        $this->assertStringContainsString('beside-label', $html);
        $this->assertStringContainsString('beside-name', $html);
        $this->assertStringContainsString('Box A3', $html);
        $this->assertStringNotContainsString('stacked-label', $html);
    }

    public function testDefaultQrLayoutStaysSideBySideWithoutMarker(): void
    {
        $options = $this->options(withStackedMarker: false);
        $options->setLines('[[NAME]]');
        $html = $this->service->getLabelHTML($options, [$this->part()]);

        $this->assertStringContainsString('col-5', $html);
        $this->assertStringNotContainsString('stacked-label', $html);
    }

    private function options(bool $withStackedMarker): LabelOptions
    {
        $options = new LabelOptions();
        $options->setSupportedElement(LabelSupportedElement::PART);
        $options->setBarcodeType(BarcodeType::QR);
        $options->setProcessMode(LabelProcessMode::PLACEHOLDER);
        $options->setWidth(30.0);
        $options->setHeight(18.0);
        $options->setLines(InstallP700LabelProfilesCommand::LINES);
        $options->setAdditionalCss($withStackedMarker ? '/* label-layout-stacked */' : '');

        return $options;
    }

    private function part(): Part
    {
        $part = new Part();
        $part->setName('DIN 912 M6 x 20');
        $part->setDescription('Socket head cap screw, black oxide, 12.9');

        return $part;
    }
}

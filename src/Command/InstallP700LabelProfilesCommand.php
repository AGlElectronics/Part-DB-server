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

namespace App\Command;

use App\Entity\LabelSystem\BarcodeType;
use App\Entity\LabelSystem\LabelOptions;
use App\Entity\LabelSystem\LabelProcessMode;
use App\Entity\LabelSystem\LabelProfile;
use App\Entity\LabelSystem\LabelSupportedElement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'partdb:labels:install-p700',
    description: 'Create or update Brother P-touch P700 18mm and 24mm part label profiles.'
)]
final class InstallP700LabelProfilesCommand extends Command
{
    public const LAYOUT_MARKER = 'label-layout-stacked';
    public const LINES = '<div class="stacked-name">[[NAME]]</div>'."\n"
        .'<div class="stacked-desc">[[DESCRIPTION_T]]</div>';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created or updated.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $created = 0;
        $updated = 0;

        foreach ($this->profiles() as $spec) {
            $profile = $this->findProfile($spec['name']);
            if ($profile === null) {
                $profile = new LabelProfile();
                $profile->setName($spec['name']);
                ++$created;
                $action = 'created';
            } else {
                ++$updated;
                $action = 'updated';
            }

            $this->applySpec($profile, $spec);

            if (!$dryRun) {
                $this->entityManager->persist($profile);
            }

            $io->text(sprintf('%s %s (%0.0f x %0.0f mm)', $action, $spec['name'], $spec['width'], $spec['height']));
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'P700 label profiles %s: %d created, %d updated.',
            $dryRun ? 'would be installed' : 'installed',
            $created,
            $updated
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{name: string, width: float, height: float, additional_css: string, comment: string}>
     */
    private function profiles(): array
    {
        return [
            [
                'name' => 'P700 18mm',
                'width' => 30.0,
                'height' => 18.0,
                'additional_css' => $this->additionalCss(
                    width: 30.0,
                    height: 18.0,
                    maxQrHeight: '11mm',
                    nameSize: '6.5pt',
                    descSize: '5.5pt',
                    pageMargin: '0.5mm',
                ),
                'comment' => 'Brother P-touch P700 18mm TZe tape. QR opens the part on parts.4qt.org.',
            ],
            [
                'name' => 'P700 24mm',
                'width' => 30.0,
                'height' => 24.0,
                'additional_css' => $this->additionalCss(
                    width: 30.0,
                    height: 24.0,
                    maxQrHeight: '15mm',
                    nameSize: '8pt',
                    descSize: '6.5pt',
                    pageMargin: '0.6mm',
                ),
                'comment' => 'Brother P-touch P700 24mm TZe tape. QR opens the part on parts.4qt.org.',
            ],
        ];
    }

    private function additionalCss(
        float $width,
        float $height,
        string $maxQrHeight,
        string $nameSize,
        string $descSize,
        string $pageMargin,
    ): string {
        return <<<CSS
/* label-layout-stacked */
html, body { margin: 0; padding: 0; width: 100%; height: 100%; }
@page { size: {$width}mm {$height}mm; margin: {$pageMargin}; }
.stacked-qr { height: {$maxQrHeight}; max-height: {$maxQrHeight}; }
.stacked-name { font-size: {$nameSize}; }
.stacked-desc { font-size: {$descSize}; }
CSS;
    }

    /**
     * @param array{name: string, width: float, height: float, additional_css: string, comment: string} $spec
     */
    private function applySpec(LabelProfile $profile, array $spec): void
    {
        $options = $profile->getOptions();
        $options->setWidth($spec['width']);
        $options->setHeight($spec['height']);
        $options->setBarcodeType(BarcodeType::QR);
        $options->setSupportedElement(LabelSupportedElement::PART);
        $options->setProcessMode(LabelProcessMode::PLACEHOLDER);
        $options->setLines(self::LINES);
        $options->setAdditionalCss($spec['additional_css']);
        $profile->setOptions($options);
        $profile->setShowInDropdown(true);
        $profile->setComment($spec['comment']);
    }

    private function findProfile(string $name): ?LabelProfile
    {
        return $this->entityManager->getRepository(LabelProfile::class)->findOneBy([
            'name' => $name,
            'options.supported_element' => LabelSupportedElement::PART,
        ]);
    }
}

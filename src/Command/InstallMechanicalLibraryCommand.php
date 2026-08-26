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

namespace App\Command;

use App\Entity\Parts\Category;
use App\Repository\Parts\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'partdb:mechanical-library:install',
    description: 'Install the standard mechanical part category hierarchy without changing existing categories.'
)]
final class InstallMechanicalLibraryCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/resources/mechanical/taxonomy.json')]
        private readonly string $taxonomyPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show how many categories would be created.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $taxonomy = $this->loadTaxonomy();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $created = [];
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $this->entityManager->getRepository(Category::class);
        foreach ($taxonomy['paths'] as $path) {
            foreach ($categoryRepository->getNewEntityFromPath($path) as $category) {
                if ($category->getID() === null) {
                    $created[spl_object_id($category)] = $category;
                }
            }
        }

        if ($input->getOption('dry-run')) {
            $io->success(sprintf(
                'Mechanical taxonomy version %d is valid; %d categories would be created.',
                $taxonomy['version'],
                count($created)
            ));

            return Command::SUCCESS;
        }

        foreach ($created as $category) {
            $this->entityManager->persist($category);
        }
        $this->entityManager->flush();

        $io->success(sprintf(
            'Mechanical taxonomy version %d installed; %d categories created, existing categories kept.',
            $taxonomy['version'],
            count($created)
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{version: int, paths: string[]}
     */
    private function loadTaxonomy(): array
    {
        $contents = @file_get_contents($this->taxonomyPath);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read mechanical taxonomy at "%s".', $this->taxonomyPath));
        }

        $taxonomy = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($taxonomy) || !is_int($taxonomy['version'] ?? null) || !is_array($taxonomy['paths'] ?? null)) {
            throw new \RuntimeException('The mechanical taxonomy has an invalid structure.');
        }
        foreach ($taxonomy['paths'] as $path) {
            if (!is_string($path) || !str_starts_with($path, 'Mechanical -> ')) {
                throw new \RuntimeException('Every taxonomy path must be a string below the Mechanical root.');
            }
        }

        /** @var array{version: int, paths: string[]} $taxonomy */
        return $taxonomy;
    }
}

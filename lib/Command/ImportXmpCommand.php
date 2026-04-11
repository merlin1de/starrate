<?php

declare(strict_types=1);

namespace OCA\StarRate\Command;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\TagService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ starrate:import-xmp <nc-path> [--user=<uid>] [--dry-run] [--skip-existing]
 *
 * Liest xmp:Rating und xmp:Label aus JPEG-Dateien und schreibt sie
 * in die StarRate Collaborative Tags-Datenbank.
 *
 * Typischer Anwendungsfall: einmaliger Import einer bestehenden
 * Lightroom/digiKam-Bibliothek in StarRate.
 */
class ImportXmpCommand extends Command
{
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly ExifService $exifService,
        private readonly TagService  $tagService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('starrate:import-xmp')
            ->setDescription('Import XMP ratings from JPEG files into StarRate')
            ->addArgument(
                'nc-path',
                InputArgument::REQUIRED,
                'NC folder path relative to user home (e.g. /Bilder/2024)'
            )
            ->addOption(
                'user', 'u',
                InputOption::VALUE_REQUIRED,
                'Nextcloud user ID'
            )
            ->addOption(
                'dry-run', null,
                InputOption::VALUE_NONE,
                'Show what would be imported without making changes'
            )
            ->addOption(
                'skip-existing', null,
                InputOption::VALUE_NONE,
                'Skip files that already have a rating in StarRate'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ncPath       = $input->getArgument('nc-path');
        $userId       = $input->getOption('user');
        $dryRun       = (bool) $input->getOption('dry-run');
        $skipExisting = (bool) $input->getOption('skip-existing');

        if (!$userId) {
            $output->writeln('<error>--user is required</error>');
            return Command::FAILURE;
        }

        // Ordner auflösen
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $node       = $ncPath === '/' ? $userFolder : $userFolder->get(ltrim($ncPath, '/'));
        } catch (NotFoundException) {
            $output->writeln("<error>Folder not found: {$ncPath}</error>");
            return Command::FAILURE;
        }

        if (!($node instanceof Folder)) {
            $output->writeln("<error>Path is not a folder: {$ncPath}</error>");
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>[dry-run] No changes will be written.</comment>');
        }

        $imported  = 0;
        $skipped   = 0;
        $noXmp     = 0;
        $errors    = 0;
        $nonJpeg   = 0;
        $processed = 0;

        $files = $node->getDirectoryListing();
        $total = count($files);
        $output->writeln(sprintf('Scanning %d files in %s...', $total, $ncPath));

        foreach ($files as $file) {
            if (!($file instanceof File)) {
                continue;
            }

            $processed++;
            if ($processed % 100 === 0) {
                $output->writeln(sprintf('  [%d/%d] ...', $processed, $total));
            }

            $mime = $file->getMimeType();
            if (!in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
                $nonJpeg++;
                continue;
            }

            try {
                // XMP aus Datei lesen
                $xmp = $this->exifService->readMetadata($file);

                if ($xmp['rating'] === 0 && $xmp['label'] === null) {
                    $noXmp++;
                    continue;
                }

                $fileId = (string) $file->getId();

                // Bestehende StarRate-Bewertung prüfen
                if ($skipExisting) {
                    $existing = $this->tagService->getMetadata($fileId);
                    if ($existing['rating'] > 0 || $existing['color'] !== null) {
                        $skipped++;
                        if ($output->isVerbose()) {
                            $output->writeln(sprintf(
                                '  skip  %s (already rated: %d★ %s)',
                                $file->getName(),
                                $existing['rating'],
                                $existing['color'] ?? '—'
                            ));
                        }
                        continue;
                    }
                }

                $label = $xmp['label'] ?? null;

                if ($dryRun) {
                    if ($output->isVerbose()) {
                        $output->writeln(sprintf(
                            '  <info>import</info> %s  rating=%d  label=%s',
                            $file->getName(),
                            $xmp['rating'],
                            $label ?? '—'
                        ));
                    }
                    $imported++;
                    continue;
                }

                // In StarRate-Tags schreiben (xmp:Label → color)
                $this->tagService->setMetadata($fileId, [
                    'rating' => $xmp['rating'],
                    'color'  => $label,          // null = kein Label (löscht vorhandenes)
                ]);

                if ($output->isVerbose()) {
                    $output->writeln(sprintf(
                        '  <info>import</info> %s  rating=%d  label=%s',
                        $file->getName(),
                        $xmp['rating'],
                        $label ?? '—'
                    ));
                }
                $imported++;

            } catch (\Exception $e) {
                $errors++;
                $output->writeln(sprintf(
                    '  <error>error</error>  %s: %s',
                    $file->getName(),
                    $e->getMessage()
                ));
            }
        }

        $output->writeln(sprintf(
            '%s<comment>Done:</comment> %d imported, %d skipped (already rated), %d without XMP, %d non-JPEG, %d errors.',
            $dryRun ? '[dry-run] ' : '',
            $imported,
            $skipped,
            $noXmp,
            $nonJpeg,
            $errors
        ));

        return Command::SUCCESS;
    }
}

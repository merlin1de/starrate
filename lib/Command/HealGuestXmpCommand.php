<?php

declare(strict_types=1);

namespace OCA\StarRate\Command;

use OCA\StarRate\Service\ShareService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ starrate:heal-guest-xmp [--user=<uid>] [--write]
 *
 * Heilt gast-bewertete JPEGs, deren DB-Tag und JPEG-XMP durch die alte
 * „Gast schreibt kein XMP"-Lücke (vor dem Fix) auseinanderlaufen — und die das
 * Self-Healing sonst beim Öffnen auf den veralteten XMP-Wert zurücksetzt.
 *
 * Quelle ist das Gast-Log. Konfliktsicher: nur Dateien, die NACH der Gast-Bewertung
 * nicht extern (LR/digiKam) bearbeitet wurden (Datei-mtime ≤ Log-Zeit).
 *
 * Default = Dry-Run (zeigt nur an). --write schreibt DB + XMP.
 */
class HealGuestXmpCommand extends Command
{
    public function __construct(
        private readonly ShareService $shareService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('starrate:heal-guest-xmp')
            ->setDescription('Heal guest ratings that never reached the JPEG XMP (legacy drift fix)')
            ->addOption(
                'user', 'u',
                InputOption::VALUE_REQUIRED,
                'Limit to one Nextcloud user (share owner). Default: all share owners.'
            )
            ->addOption(
                'write', null,
                InputOption::VALUE_NONE,
                'Actually write DB + XMP. Default: dry-run, no changes.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write  = (bool) $input->getOption('write');
        $userId = $input->getOption('user');

        $owners = ($userId !== null && $userId !== '')
            ? [$userId]
            : $this->shareService->getAllShareOwners();
        if (empty($owners)) {
            $output->writeln('<comment>No StarRate share owners found — nothing to heal.</comment>');
            return Command::SUCCESS;
        }

        if (!$write) {
            $output->writeln('<comment>[dry-run] No changes will be written. Pass --write to heal.</comment>');
        }

        $grand = [
            'folded' => 0, 'candidates' => 0, 'healed' => 0, 'skipped_mtime' => 0,
            'in_sync' => 0, 'non_jpeg' => 0, 'not_found' => 0, 'errors' => 0,
        ];

        foreach ($owners as $owner) {
            $report = $this->shareService->healGuestXmp($owner, $write);

            if ($report['write_xmp'] === false) {
                $output->writeln(sprintf(
                    'User <info>%s</info>: write_xmp disabled — skipped (no drift possible).',
                    $owner
                ));
                continue;
            }

            $s = $report['stats'];
            foreach (array_keys($grand) as $k) {
                $grand[$k] += $s[$k];
            }

            $output->writeln(sprintf(
                'User <info>%s</info>: %d guest-rated, %d %s, %d in sync, %d edited-later, %d non-JPEG, %d gone, %d errors.',
                $owner,
                $s['folded'],
                $write ? $s['healed'] : $s['candidates'],
                $write ? 'healed' : 'to heal',
                $s['in_sync'],
                $s['skipped_mtime'],
                $s['non_jpeg'],
                $s['not_found'],
                $s['errors']
            ));

            foreach ($report['details'] as $d) {
                $output->writeln(sprintf(
                    '    %s %s  guest[%s] ← xmp[%s]',
                    $write ? '<info>heal</info>' : '<comment>would heal</comment>',
                    $d['name'],
                    $this->fmt($d['guest']),
                    $this->fmt($d['xmp'])
                ));
            }
        }

        $output->writeln(sprintf(
            '%s<comment>Total:</comment> %d %s across %d guest-rated files (%d skipped as edited-later, %d errors).',
            $write ? '' : '[dry-run] ',
            $write ? $grand['healed'] : $grand['candidates'],
            $write ? 'healed' : 'to heal',
            $grand['folded'],
            $grand['skipped_mtime'],
            $grand['errors']
        ));

        return Command::SUCCESS;
    }

    /**
     * Formatiert ein {rating,color,pick}-Tripel kompakt für die Ausgabe.
     * null = vom Gast nicht gesetzt (–), '' = Farbe gelöscht (no-color).
     *
     * @param array{rating: int|null, color: string|null, pick: string|null} $v
     */
    private function fmt(array $v): string
    {
        $r = $v['rating'] === null ? '–' : ($v['rating'] . '★');
        $c = ($v['color'] ?? null) === null ? '–' : ($v['color'] === '' ? 'no-color' : $v['color']);
        $p = ($v['pick'] ?? null) === null ? '–' : $v['pick'];
        return "{$r} {$c} {$p}";
    }
}

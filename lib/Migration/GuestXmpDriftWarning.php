<?php

declare(strict_types=1);

namespace OCA\StarRate\Migration;

use OCA\StarRate\Service\ShareService;
use OCA\StarRate\Settings\UserSettings;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Warnt beim App-Upgrade, wenn gast-bewertete Dateien existieren, deren XMP durch die
 * alte „Gast schreibt kein XMP"-Lücke driften könnte.
 *
 * Schreibt bewusst NICHTS (keine stillen JPEG-Änderungen beim Upgrade) — die
 * eigentliche, konfliktsichere Heilung läuft transparent per `occ starrate:heal-guest-xmp`
 * (Dry-Run-Default). Der Scan ist günstig: nur Config-Reads, kein Datei-I/O.
 */
class GuestXmpDriftWarning implements IRepairStep
{
    public function __construct(
        private readonly ShareService $shareService,
        private readonly UserSettings $userSettings,
    ) {}

    public function getName(): string
    {
        return 'StarRate: check guest ratings for XMP drift';
    }

    public function run(IOutput $output): void
    {
        $atRisk   = 0;
        $affected = 0;

        foreach ($this->shareService->getAllShareOwners() as $owner) {
            // Kein write_xmp → kein Self-Healing → kein Drift möglich.
            if (!$this->userSettings->getSettings($owner)['write_xmp']) {
                continue;
            }
            $n = $this->shareService->countGuestRatedFiles($owner);
            if ($n > 0) {
                $atRisk += $n;
                $affected++;
            }
        }

        if ($atRisk === 0) {
            return;
        }

        $output->warning(sprintf(
            'StarRate: up to %d guest-rated file(s) across %d user(s) may have ratings not yet written '
            . 'to their JPEG XMP. Run "occ starrate:heal-guest-xmp" to review (dry-run), '
            . 'then add "--write" to heal.',
            $atRisk,
            $affected
        ));
    }
}

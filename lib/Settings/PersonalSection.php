<?php

declare(strict_types=1);

namespace OCA\StarRate\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection
{
    public function __construct(
        private readonly IL10N         $l,
        private readonly IURLGenerator $url,
    ) {}

    public function getID(): string
    {
        return 'starrate';
    }

    public function getName(): string
    {
        return 'StarRate';
    }

    public function getPriority(): int
    {
        return 75;
    }

    public function getIcon(): string
    {
        return $this->url->imagePath('starrate', 'app.svg');
    }
}

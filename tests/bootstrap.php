<?php

declare(strict_types=1);

// Load Nextcloud's composer autoloader so OCP\* interfaces are available for mocking.
// The path is absolute to the NC server root inside the Docker container.
$ncAutoload = '/var/www/html/lib/composer/autoload.php';
if (file_exists($ncAutoload)) {
    require_once $ncAutoload;
}

// Load the app's own vendor autoloader (PHPUnit, etc.).
require_once __DIR__ . '/../vendor/autoload.php';

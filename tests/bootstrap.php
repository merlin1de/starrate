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

// Polyfill: OCP\DB interfaces reference Doctrine\DBAL classes that are not installed
// in the test environment. Define minimal stand-ins so PHPUnit can mock them.
$doctrinePolyfills = [
    'Doctrine\DBAL\ParameterType' =>
        'class ParameterType { const STRING = 2; const INTEGER = 1; const NULL = 0; const BOOLEAN = 5; const BINARY = 16; const LARGE_OBJECT = 17; const ASCII = 18; }',
    'Doctrine\DBAL\ArrayParameterType' =>
        'class ArrayParameterType { const STRING = 101; const INTEGER = 102; }',
    'Doctrine\DBAL\Connection' =>
        'class Connection {}',
    'Doctrine\DBAL\Exception' =>
        'class Exception extends \Exception {}',
    'Doctrine\DBAL\Types\Types' =>
        'class Types { const STRING = "string"; const INTEGER = "integer"; const BOOLEAN = "boolean"; const TEXT = "text"; const DATETIME_MUTABLE = "datetime"; const DATETIME_IMMUTABLE = "datetime_immutable"; const DATETIMETZ_MUTABLE = "datetimetz"; const DATETIMETZ_IMMUTABLE = "datetimetz_immutable"; const DATE_MUTABLE = "date"; const DATE_IMMUTABLE = "date_immutable"; const TIME_MUTABLE = "time"; const BLOB = "blob"; const BIGINT = "bigint"; const SMALLINT = "smallint"; const FLOAT = "float"; const DECIMAL = "decimal"; const BINARY = "binary"; const JSON = "json"; }',
    'Doctrine\DBAL\Platforms\AbstractPlatform' =>
        'abstract class AbstractPlatform {}',
    'Doctrine\DBAL\Query\Expression\ExpressionBuilder' =>
        'class ExpressionBuilder { const EQ = "="; const NEQ = "<>"; const LT = "<"; const LTE = "<="; const GT = ">"; const GTE = ">="; }',
    'Doctrine\DBAL\Query\Expression\CompositeExpression' =>
        'class CompositeExpression {}',
];
foreach ($doctrinePolyfills as $class => $body) {
    if (!class_exists($class)) {
        $ns = substr($class, 0, (int) strrpos($class, '\\'));
        eval("namespace $ns; $body");
    }
}

<?php
/**
 * Autoloading: prefer Composer classmap when present. Fall back to a
 * minimal PSR-4 autoloader so the plugin still boots if vendor/ is absent.
 *
 * @package Bileto
 */

declare(strict_types=1);

namespace Bileto;

defined('ABSPATH') || exit;

$bileto_composer = __DIR__ . '/vendor/autoload.php';
if (is_readable($bileto_composer)) {
    require_once $bileto_composer;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix  = 'Bileto\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = substr($class, $len);
    $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

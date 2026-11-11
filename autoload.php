<?php
/**
 * Autoloading through Composer. The plugin does not boot without vendor/.
 *
 * @package Bileto
 */

declare(strict_types=1);

namespace Bileto;

defined('ABSPATH') || exit;

// Own classes and the Strauss-prefixed libraries (vendor-prefixed/, Bileto\Vendor\...) through one Composer classmap.
$bileto_composer = __DIR__ . '/vendor/autoload.php';
if (is_readable($bileto_composer)) {
    require_once $bileto_composer;
    return;
}

// The QR library ships in vendor/; without it the plugin cannot issue tickets.
add_action('admin_notices', static function (): void {
    echo '<div class="notice notice-error"><p>' . esc_html__('Bileto is missing its vendor directories. Reinstall the plugin from its release zip.', 'bileto') . '</p></div>';
});

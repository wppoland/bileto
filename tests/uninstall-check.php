<?php

/**
 * Uninstall keeps ticket data unless the owner opted in, and on a network it
 * visits every site. Sites 1 and 3 opted in, site 2 did not.
 *
 * Run: php tests/uninstall-check.php
 */

declare(strict_types=1);

define('WP_UNINSTALL_PLUGIN', 'bileto/bileto.php');

$blog    = 1;
$deleted = [];

function is_multisite(): bool { return true; }
function get_sites(array $args): array { return [1, 2, 3]; }
function switch_to_blog(int $id): void { $GLOBALS['blog'] = $id; }
function restore_current_blog(): void { $GLOBALS['blog'] = 1; }
function get_option(string $name, $default = false) { return $GLOBALS['blog'] === 2 ? [] : ['delete_data' => true]; }
function delete_option(string $name): void { $GLOBALS['deleted'][] = $GLOBALS['blog'] . ':' . $name; }

require __DIR__ . '/../uninstall.php';

$expected = ['1:bileto_settings', '3:bileto_settings'];
sort($deleted);

if ($deleted !== $expected) {
    echo 'FAIL: uninstall deleted ' . implode(', ', $deleted) . "\n";
    exit(1);
}

echo "OK: uninstall respects the opt-in on every site of the network\n";

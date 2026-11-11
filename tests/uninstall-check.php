<?php

/**
 * Uninstall drops ticket tables only on sites that opted in, always removes
 * options and the Check-in role, and visits every site of a network.
 * Sites 1 and 3 opted in, site 2 did not.
 *
 * Run: php tests/uninstall-check.php
 */

declare(strict_types=1);

define('WP_UNINSTALL_PLUGIN', 'bileto/bileto.php');

$blog = 1;
$log  = [];

final class FakeWpdb
{
    public string $prefix = 'wp_';

    public function query(string $sql): void
    {
        $GLOBALS['log'][] = $GLOBALS['blog'] . ':' . $sql;
    }
}

final class FakeRole
{
    public function remove_cap(string $cap): void
    {
        $GLOBALS['log'][] = $GLOBALS['blog'] . ':cap:' . $cap;
    }
}

$wpdb = new FakeWpdb();

function is_multisite(): bool { return true; }
function get_sites(array $args): array { return [1, 2, 3]; }
function switch_to_blog(int $id): void { $GLOBALS['blog'] = $id; }
function restore_current_blog(): void { $GLOBALS['blog'] = 1; }
function get_option(string $name, $default = false) { return $name === 'bileto_delete_data' ? ($GLOBALS['blog'] === 2 ? false : 'yes') : 7; }
function delete_option(string $name): void { $GLOBALS['log'][] = $GLOBALS['blog'] . ':option:' . $name; }
function wp_delete_post(int $id, bool $force): void { $GLOBALS['log'][] = $GLOBALS['blog'] . ':page:' . $id; }
function remove_role(string $role): void { $GLOBALS['log'][] = $GLOBALS['blog'] . ':role:' . $role; }
function get_role(string $role): FakeRole { return new FakeRole(); }

require __DIR__ . '/../uninstall.php';

$drops = static fn (int $site): int => count(array_filter($log, static fn (string $l): bool => str_starts_with($l, $site . ':DROP TABLE')));
$fail  = [];
if ($drops(1) !== 3 || $drops(3) !== 3) {
    $fail[] = 'opted-in sites must drop 3 tables';
}
if ($drops(2) !== 0) {
    $fail[] = 'site 2 did not opt in but lost its tickets';
}
foreach ([1, 2, 3] as $site) {
    if (! in_array($site . ':role:bileto_checkin', $log, true) || ! in_array($site . ':option:bileto_db_version', $log, true)) {
        $fail[] = "site {$site}: role or options left behind";
    }
}

if ($fail !== []) {
    echo 'FAIL: ' . implode('; ', $fail) . "\n";
    exit(1);
}

echo "OK: uninstall keeps tickets unless opted in, cleans roles and options on every site\n";

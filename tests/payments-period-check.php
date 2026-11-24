<?php

/**
 * The B2C payments report turns a preset or a custom range into whole days:
 * months, quarters and years roll over correctly, a reversed range is swapped,
 * and anything unreadable falls back to this month.
 *
 * Run: php tests/payments-period-check.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../src/Contract/HasHooks.php';
require __DIR__ . '/../src/Admin/PaymentsReport.php';

use Bileto\Admin\PaymentsReport;

$failures = 0;
$today    = new DateTimeImmutable('2026-01-31');
$cases    = [
    [['month', '', ''], ['month', '2026-01-01', '2026-01-31']],
    [['last-month', '', ''], ['last-month', '2025-12-01', '2025-12-31']],
    [['quarter', '', ''], ['quarter', '2026-01-01', '2026-03-31']],
    [['last-quarter', '', ''], ['last-quarter', '2025-10-01', '2025-12-31']],
    [['year', '', ''], ['year', '2026-01-01', '2026-12-31']],
    [['last-year', '', ''], ['last-year', '2025-01-01', '2025-12-31']],
    [['custom', '2026-03-10', '2026-02-01'], ['custom', '2026-02-01', '2026-03-10']],
    [['custom', '2026-02-30', '2026-03-01'], ['month', '2026-01-01', '2026-01-31']],
    [['bogus', '', ''], ['month', '2026-01-01', '2026-01-31']],
];
foreach ($cases as [$in, $want]) {
    $got = PaymentsReport::period($in[0], $in[1], $in[2], $today);
    $ok  = $got === $want;
    echo ($ok ? 'OK   ' : 'FAIL ') . implode(' ', $in) . ' => ' . implode(' ', $got) . "\n";
    $failures += $ok ? 0 : 1;
}
$may = PaymentsReport::period('last-month', '', '', new DateTimeImmutable('2026-03-31'));
$ok  = $may === ['last-month', '2026-02-01', '2026-02-28'];
echo ($ok ? 'OK   ' : 'FAIL ') . "last month from 31 March is February\n";
$failures += $ok ? 0 : 1;

echo $failures === 0 ? "All checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);

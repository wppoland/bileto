<?php

// existing-checked: src/Admin/TicketsScreen.php - it lists the tickets of one event; this report lists paid orders of the whole shop by date, so it is a screen of its own that reuses TicketsScreen::csvCell.

declare(strict_types=1);

namespace Bileto\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;

defined('ABSPATH') || exit;

/**
 * Events > B2C payments: every paid order of a private buyer in a period, with
 * the gateway references that tie the payment to the order. Evidence that a
 * sale to a consumer was paid through a bank or payment institution (Poland:
 * cash-register exemption, item 42 of the MF regulation of 17.12.2024).
 *
 * Reads only what the order stores; it never calls a gateway API. A buyer with
 * a NIP or EU VAT ID is a business; filter `bileto_buyer_is_business` (bool,
 * \WC_Order) overrides that.
 */
final class PaymentsReport implements HasHooks
{
    private const CAP     = 'manage_woocommerce';
    private const SLUG    = 'bileto-b2c-payments';
    private const EXPORT  = 'bileto_export_b2c_payments';
    private const PRESETS = ['month', 'last-month', 'quarter', 'last-quarter', 'year', 'last-year'];

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::EXPORT, [$this, 'export']);
    }

    public function menu(): void
    {
        add_submenu_page('edit.php?post_type=' . EventPostType::POST_TYPE, __('B2C payments', 'bileto'), __('B2C payments', 'bileto'), self::CAP, self::SLUG, [$this, 'render']);
    }

    public function render(): void
    {
        [$preset, $from, $to] = self::requested();
        $rows   = self::rows($from, $to);
        $totals = self::totals($rows);
        $base   = ['post_type' => EventPostType::POST_TYPE, 'page' => self::SLUG];
        $labels = [
            'month'        => __('This month', 'bileto'),
            'last-month'   => __('Last month', 'bileto'),
            'quarter'      => __('This quarter', 'bileto'),
            'last-quarter' => __('Last quarter', 'bileto'),
            'year'         => __('This year', 'bileto'),
            'last-year'    => __('Last year', 'bileto'),
        ];
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('B2C payments', 'bileto'); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => self::EXPORT, 'period' => 'custom', 'from' => $from, 'to' => $to], admin_url('admin-post.php')), self::EXPORT)); ?>"><?php esc_html_e('Export CSV', 'bileto'); ?></a>
            <hr class="wp-header-end">
            <p><?php esc_html_e('Paid orders of private buyers (no NIP or EU VAT ID) with the payment references that tie each payment to its order. Data stored on the order only.', 'bileto'); ?></p>
            <ul class="subsubsub">
                <?php foreach ($labels as $key => $label) : ?>
                    <li><a href="<?php echo esc_url(add_query_arg($base + ['period' => $key], admin_url('edit.php'))); ?>" <?php echo $key === $preset ? 'class="current" aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a><?php echo $key === 'last-year' ? '' : ' |'; ?></li>
                <?php endforeach; ?>
            </ul>
            <form method="get" class="bileto-period" style="clear:both;padding-top:8px">
                <?php foreach ($base + ['period' => 'custom'] as $name => $value) : ?>
                    <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endforeach; ?>
                <label for="bileto-from"><?php esc_html_e('From', 'bileto'); ?></label>
                <input type="date" id="bileto-from" name="from" value="<?php echo esc_attr($from); ?>" required>
                <label for="bileto-to"><?php esc_html_e('To', 'bileto'); ?></label>
                <input type="date" id="bileto-to" name="to" value="<?php echo esc_attr($to); ?>" required>
                <button type="submit" class="button"><?php esc_html_e('Show', 'bileto'); ?></button>
            </form>

            <h2><?php echo esc_html(sprintf(/* translators: 1: start date, 2: end date */ __('Totals %1$s to %2$s', 'bileto'), $from, $to)); ?></h2>
            <table class="widefat striped" style="max-width:40em">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Currency', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Orders', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Gross', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Refunded', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('After refunds', 'bileto'); ?></th>
                </tr></thead>
                <tbody>
                <?php if ($totals['currencies'] === []) : ?>
                    <tr><td colspan="5"><?php esc_html_e('No B2C payments in this period.', 'bileto'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($totals['currencies'] as $currency => $sum) : ?>
                    <tr>
                        <td><?php echo esc_html($currency); ?></td>
                        <td><?php echo esc_html((string) $sum['orders']); ?></td>
                        <td><?php echo wp_kses_post(wc_price($sum['gross'], ['currency' => $currency])); ?></td>
                        <td><?php echo wp_kses_post(wc_price($sum['refunded'], ['currency' => $currency])); ?></td>
                        <td><?php echo wp_kses_post(wc_price($sum['net'], ['currency' => $currency])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($totals['currencies'] !== []) : ?>
                    <tfoot><tr>
                        <th scope="row" colspan="4"><?php esc_html_e('Total in PLN after refunds', 'bileto'); ?><?php echo $totals['pln_missing'] > 0 ? ' (' . esc_html(sprintf(/* translators: %d: number of orders */ _n('%d order without a PLN amount', '%d orders without a PLN amount', $totals['pln_missing'], 'bileto'), $totals['pln_missing'])) . ')' : ''; ?></th>
                        <td><?php echo wp_kses_post(wc_price($totals['pln'], ['currency' => 'PLN'])); ?></td>
                    </tr></tfoot>
                <?php endif; ?>
            </table>

            <h2><?php esc_html_e('Orders', 'bileto'); ?></h2>
            <div style="overflow-x:auto">
            <table class="widefat striped">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Order', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Date paid', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Gross', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Refunded', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('After refunds', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('PLN', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Payment method', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Transaction ID', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Stripe payment intent', 'bileto'); ?></th>
                    <th scope="col"><?php esc_html_e('Stripe fee / net', 'bileto'); ?></th>
                </tr></thead>
                <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="10"><?php esc_html_e('No B2C payments in this period.', 'bileto'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url(OrderUtil::get_order_admin_edit_url($r['order_id'])); ?>">#<?php echo esc_html($r['order']); ?></a></td>
                        <td><?php echo esc_html($r['date_paid']); ?></td>
                        <td><?php echo wp_kses_post(wc_price($r['gross'], ['currency' => $r['currency']])); ?></td>
                        <td><?php echo $r['refunded'] > 0 ? wp_kses_post(wc_price($r['refunded'], ['currency' => $r['currency']])) : ''; ?></td>
                        <td><?php echo wp_kses_post(wc_price($r['net'], ['currency' => $r['currency']])); ?></td>
                        <td><?php echo $r['pln'] === null ? '' : wp_kses_post(wc_price($r['pln'], ['currency' => 'PLN'])); ?></td>
                        <td><?php echo esc_html($r['method']); ?></td>
                        <td><?php echo $r['transaction'] === '' ? '' : '<code>' . esc_html($r['transaction']) . '</code>'; ?></td>
                        <td><?php echo $r['intent'] === '' ? '' : '<code>' . esc_html($r['intent']) . '</code>'; ?></td>
                        <td><?php echo $r['settled_currency'] === '' ? '' : esc_html($r['fee'] . ' / ' . $r['settled'] . ' ' . $r['settled_currency']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    public function export(): void
    {
        if (! current_user_can(self::CAP)) {
            wp_die(esc_html__('You are not allowed to export payments.', 'bileto'), 403);
        }
        check_admin_referer(self::EXPORT);
        [, $from, $to] = self::requested();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('b2c-payments-' . $from . '-' . $to . '.csv') . '"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, so Excel shows Polish letters.
        fputcsv($out, ['order', 'date_paid', 'gross', 'refunded', 'after_refunds', 'currency', 'pln', 'payment_method', 'transaction_id', 'stripe_intent_id', 'stripe_fee', 'stripe_net', 'stripe_currency'], ',', '"', '');
        foreach (self::rows($from, $to) as $r) {
            // Amounts come from floats, so only the text cells need the formula guard.
            $text = array_map([TicketsScreen::class, 'csvCell'], [$r['order'], $r['method'], $r['transaction'], $r['intent'], $r['settled_currency']]);
            fputcsv($out, [$text[0], $r['date_paid'], self::money($r['gross']), self::money($r['refunded']), self::money($r['net']), $r['currency'], $r['pln'] === null ? '' : self::money($r['pln']), $text[1], $text[2], $text[3], self::metaMoney($r['fee']), self::metaMoney($r['settled']), $text[4]], ',', '"', '');
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream.
        exit;
    }

    /**
     * Paid B2C orders above zero with a payment date in [$from, $to] (site time, whole days),
     * oldest first. Fully refunded orders stay in, so the refund shows.
     *
     * @return list<array{order_id: int, order: string, date_paid: string, gross: float, refunded: float, net: float, currency: string, pln: ?float, method: string, transaction: string, intent: string, fee: string, settled: string, settled_currency: string}>
     */
    public static function rows(string $from, string $to): array
    {
        $orders = wc_get_orders([
            'type'      => 'shop_order',
            'status'    => array_merge(wc_get_is_paid_statuses(), ['refunded']),
            // Epoch bounds in site time, so both order stores read the same whole days.
            'date_paid' => (new \DateTimeImmutable($from . ' 00:00:00', wp_timezone()))->getTimestamp() . '...' . (new \DateTimeImmutable($to . ' 23:59:59', wp_timezone()))->getTimestamp(),
            'limit'     => -1,
        ]);
        $rows   = [];
        foreach ($orders as $order) {
            $paid = $order instanceof \WC_Order ? $order->get_date_paid() : null;
            // A free order (0.00) has no payment to prove.
            if ($paid === null || (float) $order->get_total() <= 0 || self::isBusiness($order)) {
                continue;
            }
            $gross    = (float) $order->get_total();
            $refunded = (float) $order->get_total_refunded();
            $currency = $order->get_currency();
            $fee      = (string) $order->get_meta('_stripe_fee');
            $settled  = (string) $order->get_meta('_stripe_net');
            $stripeCc = strtoupper((string) $order->get_meta('_stripe_currency'));
            // Stripe keeps fee and net in the account currency, refunds included, so
            // their sum is the PLN amount kept when a foreign order settles in PLN.
            $pln = $currency === 'PLN' ? $gross - $refunded : ($stripeCc === 'PLN' && $settled !== '' ? (float) $fee + (float) $settled : null);

            $rows[] = [
                'order_id'         => $order->get_id(),
                'order'            => (string) $order->get_order_number(),
                'date_paid'        => wp_date('Y-m-d H:i', $paid->getTimestamp()),
                'gross'            => $gross,
                'refunded'         => $refunded,
                'net'              => $gross - $refunded,
                'currency'         => $currency,
                'pln'              => $pln,
                'method'           => $order->get_payment_method_title(),
                'transaction'      => $order->get_transaction_id(),
                'intent'           => (string) $order->get_meta('_stripe_intent_id'),
                'fee'              => $fee,
                'settled'          => $settled,
                'settled_currency' => $stripeCc,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['date_paid'], $b['date_paid']));

        return $rows;
    }

    /** A NIP (Polski fields) or EU VAT ID on the order makes the buyer a business. */
    public static function isBusiness(\WC_Order $order): bool
    {
        $business = false;
        foreach (['_billing_nip', '_polski_billing_nip', '_billing_vat_id'] as $key) {
            $business = $business || trim((string) $order->get_meta($key)) !== '';
        }

        return (bool) apply_filters('bileto_buyer_is_business', $business, $order);
    }

    /**
     * First and last day (Y-m-d) of a preset period around $today, or of a custom
     * range; anything unreadable falls back to this month.
     *
     * @return array{0: string, 1: string, 2: string} Preset, from, to.
     */
    public static function period(string $preset, string $from, string $to, \DateTimeImmutable $today): array
    {
        $valid = static fn (string $d): bool => ($t = \DateTimeImmutable::createFromFormat('!Y-m-d', $d)) !== false && $t->format('Y-m-d') === $d;
        if ($preset === 'custom' && $valid($from) && $valid($to)) {
            return $from <= $to ? ['custom', $from, $to] : ['custom', $to, $from];
        }
        $preset  = in_array($preset, self::PRESETS, true) ? $preset : 'month';
        $year    = (int) $today->format('Y');
        $month   = (int) $today->format('n');
        $quarter = intdiv($month - 1, 3) * 3 + 1;
        [$start, $months] = match ($preset) {
            'month'        => [$today->setDate($year, $month, 1), 1],
            'last-month'   => [$today->setDate($year, $month - 1, 1), 1],
            'quarter'      => [$today->setDate($year, $quarter, 1), 3],
            'last-quarter' => [$today->setDate($year, $quarter - 3, 1), 3],
            'year'         => [$today->setDate($year, 1, 1), 12],
            'last-year'    => [$today->setDate($year - 1, 1, 1), 12],
        };

        return [$preset, $start->format('Y-m-d'), $start->modify('+' . $months . ' months -1 day')->format('Y-m-d')];
    }

    /**
     * @param list<array{currency: string, gross: float, refunded: float, net: float, pln: ?float}> $rows
     * @return array{currencies: array<string, array{orders: int, gross: float, refunded: float, net: float}>, pln: float, pln_missing: int}
     */
    public static function totals(array $rows): array
    {
        $totals = ['currencies' => [], 'pln' => 0.0, 'pln_missing' => 0];
        foreach ($rows as $r) {
            $sum = $totals['currencies'][$r['currency']] ?? ['orders' => 0, 'gross' => 0.0, 'refunded' => 0.0, 'net' => 0.0];
            $totals['currencies'][$r['currency']] = ['orders' => $sum['orders'] + 1, 'gross' => $sum['gross'] + $r['gross'], 'refunded' => $sum['refunded'] + $r['refunded'], 'net' => $sum['net'] + $r['net']];
            if ($r['pln'] === null) {
                $totals['pln_missing']++;
            } else {
                $totals['pln'] += $r['pln'];
            }
        }

        return $totals;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function requested(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter; the export checks its nonce first.
        $get = static fn (string $key): string => isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        $period = self::period($get('period'), $get('from'), $get('to'), new \DateTimeImmutable('now', wp_timezone()));
        // phpcs:enable

        return $period;
    }

    private static function money(float $amount): string
    {
        return wc_format_decimal($amount, wc_get_price_decimals());
    }

    /** Gateway meta is a string; a non-number never reaches the spreadsheet. */
    private static function metaMoney(string $amount): string
    {
        return is_numeric($amount) ? self::money((float) $amount) : '';
    }
}

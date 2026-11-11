<?php

declare(strict_types=1);

namespace Bileto\Import;

use Bileto\Event\EventPostType;
use Bileto\Product\TicketProduct;
use Bileto\Ticket\TicketService;

defined('ABSPATH') || exit;

/**
 * Brings ticket orders sold elsewhere (another shop, a spreadsheet) into
 * WooCommerce as history: original dates, amounts and VAT as booked, tickets
 * for paid seats, no e-mails and no recalculation. Running it twice skips
 * orders already imported.
 *
 * Input (amounts in minor units, e.g. cents):
 *
 *     {"source": "oldshop", "orders": [{
 *         "id": "A-1", "status": "completed|cancelled", "created_at": "2026-05-01T10:00:00+02:00",
 *         "paid_at": null, "currency": "EUR", "payment_method": "Card",
 *         "buyer": {"first_name": "", "last_name": "", "email": "", "company": "", "tax_id": "",
 *                   "phone": "", "address": "", "postcode": "", "city": "", "country": "PL"},
 *         "items": [{"name": "Regular ticket", "quantity": 1, "net": 10000, "tax": 2300, "tax_rate": 23}],
 *         "invoices": ["INV/1/2026"]
 *     }]}
 *
 * Each item name becomes a private ticket product of the event (reused when
 * it exists). Invoice numbers go to the order note and to
 * `_polski_external_invoice`, which tells Polski PRO the order was invoiced
 * outside the shop, so it never issues a second one. The buyer's tax ID goes
 * to `_billing_nip`, where Polski reads it.
 */
final class OrderImporter
{
    public const META = '_bileto_import_id';

    /** @var array<string, \WC_Product> */
    private array $products = [];

    public function __construct(private TicketService $service)
    {
    }

    /**
     * Import ticket orders from a JSON file (format in the class comment).
     *
     * ## OPTIONS
     *
     * <file>
     * : JSON file.
     *
     * --event=<id>
     * : Bileto event the tickets admit to.
     *
     * [--dry-run]
     * : Validate and count without writing.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $data = json_decode((string) file_get_contents($args[0]), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local CLI input.
        if (! is_array($data) || ! isset($data['source'], $data['orders']) || ! is_array($data['orders'])) {
            \WP_CLI::error('Expected {"source": ..., "orders": [...]}.');
        }
        $result = $this->import($data, (int) ($assoc['event'] ?? 0), isset($assoc['dry-run']));
        foreach ($result['errors'] as $error) {
            \WP_CLI::warning($error);
        }
        \WP_CLI::success(sprintf('%d imported, %d already there, %d tickets, %d errors.', $result['imported'], $result['skipped'], $result['tickets'], count($result['errors'])));
    }

    /**
     * @param array{source: string, orders: list<array<string, mixed>>} $data
     * @return array{imported: int, skipped: int, tickets: int, errors: list<string>}
     */
    public function import(array $data, int $eventId, bool $dryRun = false): array
    {
        if (get_post_type($eventId) !== EventPostType::POST_TYPE) {
            throw new \InvalidArgumentException('Unknown event.');
        }
        $result         = ['imported' => 0, 'skipped' => 0, 'tickets' => 0, 'errors' => []];
        $this->products = [];
        $mute   = static fn (): bool => false;
        add_filter('pre_wp_mail', $mute, PHP_INT_MAX);
        try {
            foreach ($data['orders'] as $row) {
                $key = $data['source'] . ':' . $row['id'];
                if ($this->exists($key)) {
                    $result['skipped']++;
                    continue;
                }
                try {
                    $seats = $this->importOrder($row, (string) $data['source'], $eventId, $dryRun);
                    $result['imported']++;
                    $result['tickets'] += $seats;
                } catch (\Throwable $e) {
                    $result['errors'][] = $key . ': ' . $e->getMessage();
                }
            }
        } finally {
            remove_filter('pre_wp_mail', $mute, PHP_INT_MAX);
        }

        return $result;
    }

    private function exists(string $key): bool
    {
        return wc_get_orders(['meta_key' => self::META, 'meta_value' => $key, 'limit' => 1, 'return' => 'ids', 'status' => 'any']) !== []; // phpcs:ignore WordPress.DB.SlowDBQuery
    }

    /**
     * @param array<string, mixed> $row
     * @return int Seats issued.
     */
    private function importOrder(array $row, string $source, int $eventId, bool $dryRun): int
    {
        $status = (string) $row['status'];
        if (! in_array($status, ['completed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Unsupported status ' . esc_html($status));
        }
        $order = new \WC_Order();
        $order->set_currency(strtoupper((string) $row['currency']));
        $order->set_prices_include_tax(false);
        $order->set_created_via('bileto-import');
        $order->set_date_created((string) $row['created_at']);
        $order->set_payment_method_title((string) ($row['payment_method'] ?? ''));
        $buyer = (array) $row['buyer'];
        foreach (['first_name', 'last_name', 'email', 'company', 'phone', 'postcode', 'city', 'country'] as $field) {
            $order->{'set_billing_' . $field}((string) ($buyer[$field] ?? ''));
        }
        $order->set_billing_address_1((string) ($buyer['address'] ?? ''));
        if (($buyer['tax_id'] ?? '') !== '') {
            $order->update_meta_data('_billing_nip', (string) $buyer['tax_id']);
        }

        $gross = 0;
        $seats = 0;
        foreach ((array) $row['items'] as $line) {
            $qty  = (int) $line['quantity'];
            if ($qty < 1) {
                throw new \InvalidArgumentException('Quantity must be at least 1.');
            }
            $net  = (int) $line['net'];
            $tax  = (int) $line['tax'];
            $item = new \WC_Order_Item_Product();
            $item->set_product($this->product((string) $line['name'], $eventId, $dryRun));
            $item->set_quantity($qty);
            $item->set_subtotal(self::money($net));
            $item->set_total(self::money($net));
            if ($tax !== 0) {
                $rate = $this->rateId($eventId, (float) $line['tax_rate']);
                $item->set_taxes(['subtotal' => [$rate => self::money($tax)], 'total' => [$rate => self::money($tax)]]);
            }
            $order->add_item($item);
            $gross += $net + $tax;
            $seats += $qty;
        }

        $invoices = array_values(array_filter(array_map('strval', (array) ($row['invoices'] ?? []))));
        $order->update_meta_data(self::META, $source . ':' . $row['id']);
        if ($invoices !== []) {
            $order->update_meta_data('_polski_external_invoice', implode(', ', $invoices));
        }
        if ($dryRun) {
            return $status === 'completed' ? $seats : 0;
        }

        $order->update_taxes();
        $order->set_total(self::money($gross));
        if ($status === 'completed') {
            $order->set_date_paid((string) ($row['paid_at'] ?? '') ?: (string) $row['created_at']);
            $order->set_date_completed((string) $row['created_at']);
        }
        $order->set_status($status);
        $order->save();
        $order->add_order_note(sprintf('Imported from %s, order %s.', $source, (string) $row['id']) . ($invoices === [] ? '' : ' Invoices: ' . implode(', ', $invoices) . '.'));
        $this->service->sync($order->get_id());

        return $status === 'completed' ? $seats : 0;
    }

    private function product(string $name, int $eventId, bool $dryRun): \WC_Product
    {
        $cacheKey = $eventId . '|' . $name;
        if (isset($this->products[$cacheKey])) {
            return $this->products[$cacheKey];
        }
        foreach (wc_get_products(['status' => ['publish', 'private'], 'limit' => 50, 'name' => $name, 'return' => 'ids']) as $id) {
            $existing = wc_get_product($id);
            if ($existing instanceof \WC_Product && TicketProduct::eventId($existing) === $eventId) {
                return $this->products[$cacheKey] = $existing;
            }
        }
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_status('private');
        $product->set_virtual(true);
        TicketProduct::link($product, $eventId);
        if (! $dryRun) {
            $product->save();
        }

        return $this->products[$cacheKey] = $product;
    }

    /** The WooCommerce rate of the event country with this percentage. */
    private function rateId(int $eventId, float $percent): int
    {
        foreach (\WC_Tax::find_rates(['country' => EventPostType::country($eventId), 'tax_class' => '']) as $id => $rate) {
            if (abs((float) $rate['rate'] - $percent) < 0.001) {
                return (int) $id;
            }
        }
        throw new \RuntimeException(esc_html(sprintf('No %s%% tax rate for %s.', $percent, EventPostType::country($eventId))));
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}

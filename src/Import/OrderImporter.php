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
        $source = (string) $data['source'];
        if ($source === '' || str_contains($source, ':')) {
            throw new \InvalidArgumentException('The source must be a non-empty name without ":".');
        }
        $result         = ['imported' => 0, 'skipped' => 0, 'tickets' => 0, 'errors' => []];
        $this->products = [];
        $unmute         = self::muteMail();
        try {
            foreach ($data['orders'] as $i => $row) {
                $id = is_array($row) && isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : '';
                if ($id === '') {
                    $result['errors'][] = sprintf('row %d: missing id', $i);
                    continue;
                }
                if ($this->exists($source . ':' . $id)) {
                    $result['skipped']++;
                    continue;
                }
                try {
                    $result['tickets'] += $this->importOrder($row, $source, $id, $eventId, $dryRun);
                    $result['imported']++;
                } catch (\Throwable $e) {
                    $result['errors'][] = $source . ':' . $id . ': ' . $e->getMessage();
                }
            }
        } finally {
            $unmute();
        }

        return $result;
    }

    /**
     * Nothing may reach a buyer during the import: WooCommerce sends (or, with
     * deferred e-mails, queues for later) a mail on each status change, and
     * Bileto a ticket mail. The e-mail handlers are unhooked for the run and
     * `wp_mail()` is short-circuited as a backstop.
     *
     * @return \Closure(): void Restores everything.
     */
    private static function muteMail(): \Closure
    {
        global $wp_filter;
        $removed = [];
        foreach ($wp_filter as $hook => $callbacks) {
            foreach (['send_transactional_email', 'queue_transactional_email'] as $method) {
                $priority = has_action((string) $hook, ['WC_Emails', $method]);
                if ($priority !== false) {
                    remove_action((string) $hook, ['WC_Emails', $method], $priority);
                    $removed[] = [(string) $hook, $method, $priority];
                }
            }
        }
        $short = static fn (): bool => false;
        add_filter('pre_wp_mail', $short, PHP_INT_MAX);

        return static function () use ($removed, $short): void {
            remove_filter('pre_wp_mail', $short, PHP_INT_MAX);
            foreach ($removed as [$hook, $method, $priority]) {
                add_action($hook, ['WC_Emails', $method], $priority, 10);
            }
        };
    }

    private function exists(string $key): bool
    {
        return wc_get_orders(['meta_key' => self::META, 'meta_value' => $key, 'limit' => 1, 'return' => 'ids', 'status' => 'any']) !== []; // phpcs:ignore WordPress.DB.SlowDBQuery
    }

    /**
     * @param array<string, mixed> $row
     * @return int Seats issued.
     */
    private function importOrder(array $row, string $source, string $id, int $eventId, bool $dryRun): int
    {
        $status = (string) ($row['status'] ?? '');
        if (! in_array($status, ['completed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Unsupported status ' . esc_html($status));
        }
        $order = new \WC_Order();
        $order->set_currency(strtoupper((string) $row['currency']));
        $order->set_prices_include_tax(false);
        $order->set_created_via('bileto-import');
        $created = self::timestamp($row['created_at'] ?? null, 'created_at');
        $paid    = ($row['paid_at'] ?? null) ? self::timestamp($row['paid_at'], 'paid_at') : $created;
        $order->set_date_created($created);
        $order->set_payment_method_title((string) ($row['payment_method'] ?? ''));
        $buyer = (array) ($row['buyer'] ?? []);
        foreach (['first_name', 'last_name', 'email', 'company', 'phone', 'postcode', 'city', 'country'] as $field) {
            $order->{'set_billing_' . $field}((string) ($buyer[$field] ?? ''));
        }
        $order->set_billing_address_1((string) ($buyer['address'] ?? ''));
        if (($buyer['tax_id'] ?? '') !== '') {
            $order->update_meta_data('_billing_nip', (string) $buyer['tax_id']);
        }

        $gross = 0;
        $seats = 0;
        foreach ((array) ($row['items'] ?? []) as $line) {
            $qty = $line['quantity'] ?? null;
            $net = $line['net'] ?? null;
            $tax = $line['tax'] ?? null;
            if (! is_int($qty) || $qty < 1 || ! is_int($net) || $net < 0 || ! is_int($tax) || $tax < 0) {
                throw new \InvalidArgumentException('Each item needs an integer quantity (1 or more) and integer net and tax in minor units (0 or more).');
            }
            [$rate, $class] = $tax === 0 ? [0, ''] : $this->rate($eventId, (float) ($line['tax_rate'] ?? 0));
            $item           = new \WC_Order_Item_Product();
            $item->set_product($this->product((string) ($line['name'] ?? ''), $eventId, $class, $dryRun));
            $item->set_quantity($qty);
            $item->set_tax_class($class);
            $item->set_subtotal(self::money($net));
            $item->set_total(self::money($net));
            if ($tax !== 0) {
                $item->set_taxes(['subtotal' => [$rate => self::money($tax)], 'total' => [$rate => self::money($tax)]]);
            }
            $order->add_item($item);
            $gross += $net + $tax;
            $seats += $qty;
        }

        if ($seats === 0) {
            throw new \InvalidArgumentException('An order needs at least one item.');
        }
        $invoices = array_values(array_filter(array_map('strval', (array) ($row['invoices'] ?? []))));
        $order->update_meta_data(self::META, $source . ':' . $id);
        // Always set: an imported order was invoiced (or not) in the old shop, never here.
        $order->update_meta_data('_polski_external_invoice', $invoices !== [] ? implode(', ', $invoices) : sprintf('%s order %s', $source, $id));
        if ($dryRun) {
            return $status === 'completed' ? $seats : 0;
        }

        // History: stock, sales counts and download grants were settled in the old shop.
        $order->set_order_stock_reduced(true);
        $order->set_recorded_sales(true);
        $order->set_download_permissions_granted(true);
        $order->set_total(self::money($gross));
        if ($status === 'completed') {
            $order->set_date_paid($paid);
            $order->set_date_completed($paid);
        }
        $order->set_status($status);
        $order->update_taxes(); // Builds the tax lines and saves the order once, complete.
        $order->add_order_note(sprintf('Imported from %s, order %s.', $source, $id) . ($invoices === [] ? '' : ' Invoices: ' . implode(', ', $invoices) . '.'));
        $this->service->sync($order->get_id());

        return $status === 'completed' ? $seats : 0;
    }

    private function product(string $name, int $eventId, string $taxClass, bool $dryRun): \WC_Product
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
        $product->set_tax_class($taxClass);
        TicketProduct::link($product, $eventId);
        if (! $dryRun) {
            $product->save();
        }

        return $this->products[$cacheKey] = $product;
    }

    /**
     * The WooCommerce rate of the event country with this percentage, in any tax class.
     *
     * @return array{0: int, 1: string} Rate id and tax class slug.
     */
    private function rate(int $eventId, float $percent): array
    {
        $country = EventPostType::country($eventId);
        foreach (array_merge([''], \WC_Tax::get_tax_class_slugs()) as $class) {
            foreach (\WC_Tax::find_rates(['country' => $country, 'tax_class' => $class]) as $id => $rate) {
                if (abs((float) $rate['rate'] - $percent) < 0.001) {
                    return [(int) $id, $class];
                }
            }
        }
        throw new \RuntimeException(esc_html(sprintf('No %s%% tax rate for %s.', $percent, $country)));
    }

    /** An ISO 8601 date with its offset, as a Unix timestamp. */
    private static function timestamp(mixed $value, string $field): int
    {
        try {
            return (new \DateTimeImmutable(is_string($value) && $value !== '' ? $value : 'invalid'))->getTimestamp();
        } catch (\Exception) {
            throw new \InvalidArgumentException(esc_html($field . ' must be an ISO 8601 date.'));
        }
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}

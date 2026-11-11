<?php

declare(strict_types=1);

namespace Bileto;

use Bileto\Admin\TicketsScreen;
use Bileto\Checkin\Checkin;
use Bileto\Contract\HasHooks;
use Bileto\Email\Emails;
use Bileto\Event\EventPostType;
use Bileto\Front\TicketPage;
use Bileto\Import\OrderImporter;
use Bileto\Install\Schema;
use Bileto\Product\TicketProduct;
use Bileto\Tax\EventVat;
use Bileto\Ticket\TicketRepository;
use Bileto\Ticket\TicketService;

defined('ABSPATH') || exit;

/**
 * Main plugin coordinator for Bileto.
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var list<HasHooks> */
    private array $services;

    private OrderImporter $importer;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $tickets = new TicketRepository();
        $service = new TicketService($tickets);

        $this->importer = new OrderImporter($service);
        $this->services = [
            new EventPostType(),
            $service,
            new TicketProduct(),
            new EventVat(),
            new TicketPage($tickets, $service),
            new Emails($tickets),
            new Checkin($tickets, $service),
            new TicketsScreen($tickets),
            new Privacy($tickets),
        ];
    }

    public function boot(): void
    {
        Schema::maybeUpgrade();
        foreach ($this->services as $service) {
            $service->registerHooks();
        }
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('bileto import', $this->importer);
        }
    }
}

<?php

declare(strict_types=1);

namespace Bileto;

use Bileto\Contract\HasHooks;
use Bileto\Event\EventPostType;
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

        $this->services = [
            new EventPostType(),
            $service,
            new TicketProduct(),
            new EventVat(),
        ];
    }

    public function boot(): void
    {
        Schema::maybeUpgrade();
        foreach ($this->services as $service) {
            $service->registerHooks();
        }
    }
}

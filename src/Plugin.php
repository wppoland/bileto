<?php

declare(strict_types=1);

namespace Bileto;

use Bileto\Contract\HasHooks;

defined('ABSPATH') || exit;

/**
 * Main plugin coordinator for Bileto. Services are added per build stage
 * (see the plan): events, ticket products, tickets, transfer, check-in.
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var list<HasHooks> */
    private array $services = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        foreach ($this->services as $service) {
            $service->registerHooks();
        }
    }
}

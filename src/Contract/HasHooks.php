<?php

declare(strict_types=1);

namespace Bileto\Contract;

defined('ABSPATH') || exit;

interface HasHooks
{
    public function registerHooks(): void;
}

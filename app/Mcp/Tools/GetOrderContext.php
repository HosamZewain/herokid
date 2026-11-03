<?php

namespace App\Mcp\Tools;

class GetOrderContext extends ReadTool
{
    protected string $name = 'get_order_context';

    protected string $description = 'Current sanitized checkout and independent production-unit facts; no media URLs or raw messages.';
}

<?php

namespace App\Mcp\Tools\Sales;

class GetMyCartSummary extends SalesTool
{
    protected string $name = 'get_my_cart_summary';

    protected string $description = 'Read only the capability-bound current cart keys, quantities and field-completeness booleans.';
}

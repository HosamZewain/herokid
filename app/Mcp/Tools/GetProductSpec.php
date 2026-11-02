<?php

namespace App\Mcp\Tools;

class GetProductSpec extends ReadTool
{
    protected string $name = 'get_product_spec';

    protected string $description = 'Read the resolved specification for one unit within the checkout.';
}

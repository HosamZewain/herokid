<?php

namespace App\Mcp\Tools\Sales;

class GetStorePolicies extends SalesTool
{
    protected string $name = 'get_store_policies';

    protected string $description = 'Read a public store policy, or published automatic offers/package savings using topic=offers. No private coupon codes or customer information.';
}

<?php

namespace App\Mcp\Tools;

class GetCustomerInteractions extends ReadTool
{
    protected string $name = 'get_customer_interactions';

    protected string $description = 'Structured confirmation and approval outcomes only; no transcript.';
}

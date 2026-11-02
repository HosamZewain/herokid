<?php

namespace App\Mcp\Tools;

class GetPaymentStatus extends ReadTool
{
    protected string $name = 'get_payment_status';

    protected string $description = 'Recorded checkout payment status and one balance; requires explicit financial scope.';
}

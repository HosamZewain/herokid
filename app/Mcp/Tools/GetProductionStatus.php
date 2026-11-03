<?php

namespace App\Mcp\Tools;

class GetProductionStatus extends ReadTool
{
    protected string $name = 'get_production_status';

    protected string $description = 'Existing run state and completed/missing outputs for each unit.';
}

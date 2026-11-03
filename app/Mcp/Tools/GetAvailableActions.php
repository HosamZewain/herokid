<?php

namespace App\Mcp\Tools;

class GetAvailableActions extends ReadTool
{
    protected string $name = 'get_available_actions';

    protected string $description = 'Read-only policy proposals; no action has executed.';
}

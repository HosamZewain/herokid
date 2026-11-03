<?php

namespace App\Mcp\Tools;

class GetHumanTasks extends ReadTool
{
    protected string $name = 'get_human_tasks';

    protected string $description = 'Proposed human work using existing assignee; does not create or assign tasks.';
}

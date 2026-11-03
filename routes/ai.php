<?php

use App\Http\Middleware\EnsureAgenticReadAccess;
use App\Mcp\Servers\HeroKidReadServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/herokid', HeroKidReadServer::class)
    ->middleware(['api', 'auth:sanctum', 'throttle:30,1', EnsureAgenticReadAccess::class])
    ->name('agentic.mcp');

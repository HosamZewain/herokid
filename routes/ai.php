<?php

use App\Http\Middleware\EnsureAgenticReadAccess;
use App\Http\Middleware\SalesMcpAccess;
use App\Mcp\Servers\HeroKidReadServer;
use App\Mcp\Servers\HeroKidSalesServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/herokid', HeroKidReadServer::class)
    ->middleware(['api', 'auth:sanctum', 'throttle:30,1', EnsureAgenticReadAccess::class])
    ->name('agentic.mcp');

// This public sales surface accepts only an ephemeral, conversation-bound read capability.
Mcp::web('/mcp/storefront-sales', HeroKidSalesServer::class)
    ->middleware(['api', 'throttle:120,1', SalesMcpAccess::class])
    ->name('sales.mcp');

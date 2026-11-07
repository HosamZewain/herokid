<?php

namespace App\Mcp\Tools\Sales;

class SearchCatalog extends SalesTool
{
    protected string $name = 'search_catalog';

    protected string $description = 'Browse eligible published catalog summaries for semantic recommendations. Query describes the customer need; results are NOT filtered by literal query words. Read meanings, use kind/cursor and next_cursor for remaining pages, then get_catalog_item for complete details. Never infer no suitable product from one incomplete page.';
}

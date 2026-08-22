<?php

use App\Services\Pricing\DefaultPackageDeduplicator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(DefaultPackageDeduplicator::class)->deactivateGeneratedDuplicates();
    }

    public function down(): void
    {
        // Packages are intentionally retained. Re-enabling them automatically
        // could publish duplicate or outdated commercial offers again.
    }
};

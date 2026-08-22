<?php

namespace App\Console\Commands;

use App\Services\Pricing\DefaultPackageInstaller;
use Illuminate\Console\Command;

class InstallDefaultPackages extends Command
{
    protected $signature = 'packages:install-defaults';

    protected $description = 'Install the standard HeroKid discounted packages when their catalog products exist';

    public function handle(DefaultPackageInstaller $installer): int
    {
        $result = $installer->install();

        foreach ($result['installed'] as $name) {
            $this->info('جاهزة: '.$name);
        }
        foreach ($result['skipped'] as $message) {
            $this->warn('تم التخطي: '.$message);
        }

        return $result['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

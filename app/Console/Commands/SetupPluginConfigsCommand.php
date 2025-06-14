<?php

namespace App\Console\Commands;

use App\Models\PluginConfig;
use Database\Seeders\PluginConfigSeeder;
use Database\Seeders\PluginConfigUpdateSeeder;
use Illuminate\Console\Command;

/**
 * Artisan command for seeding plugin configurations
 *
 * Usage:
 * php artisan plugins:seed           # Seed new plugins only
 * php artisan plugins:seed --update  # Update existing configs and add new ones
 * php artisan plugins:seed --force   # Force re-seed all plugins
 */
class SetupPluginConfigsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugins:seed 
                            {--update : Update existing configurations with new defaults}
                            {--force : Force re-seed all plugins (overwrites existing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed plugin configurations from config/ai.php';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->info('Plugin Configuration Seeder');
            $this->info(str_repeat('=', 40));

            if ($this->option('force')) {
                $this->handleForceReSeed();
            } elseif ($this->option('update')) {
                $this->handleUpdate();
            } else {
                $this->handleNormalSeed();
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Seeding failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Handle normal seeding (only new plugins)
     */
    protected function handleNormalSeed(): void
    {
        $this->info('Running normal plugin seeding (new plugins only)...');
        $this->call(PluginConfigSeeder::class);
    }

    /**
     * Handle update seeding (update existing + add new)
     */
    protected function handleUpdate(): void
    {
        $this->info('Running plugin configuration update...');
        $this->call(PluginConfigUpdateSeeder::class);
    }

    /**
     * Handle force re-seeding (delete and recreate all)
     */
    protected function handleForceReSeed(): void
    {
        if (!$this->confirm('This will DELETE all existing plugin configurations. Are you sure?')) {
            $this->info('Operation cancelled.');
            return;
        }

        $this->warn('Deleting all existing plugin configurations...');

        // Delete all existing plugin configs
        PluginConfig::query()->delete();

        $this->info('Running fresh plugin seeding...');
        $this->call(PluginConfigSeeder::class);
    }
}

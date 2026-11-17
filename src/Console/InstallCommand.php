<?php

namespace Voxar\AuthSdk\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Prepares an application for Voxar Auth: configuration, migration and .env variables.
 */
class InstallCommand extends Command
{
    protected $signature = 'voxar-auth:install
        {--client= : Nom de l’application, tel que déclaré sur Voxar Auth (ex. mail)}
        {--url= : Adresse de Voxar Auth (ex. http://localhost:8002)}';

    protected $description = 'Installe le SDK Voxar Auth : configuration, migration et variables .env';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'voxar-auth-config']);
        $this->components->info('Configuration publiée : config/voxar-auth.php');

        $this->publishMigration();
        $this->writeEnvironment();

        $client = $this->option('client') ?: 'mon-app';
        $callback = rtrim(config('app.url'), '/').'/auth/callback';

        $this->newLine();
        $this->components->info('Étapes suivantes');
        $this->line('  1. Sur Voxar Auth, créez la clé de l’application :');
        $this->line("       php artisan api:token {$client}@voxar.chat --name={$client} --redirect={$callback} --create");
        $this->line('     puis collez VOXAR_AUTH_TOKEN dans le .env de cette application.');
        $this->line('  2. Ajoutez le trait au modèle User :');
        $this->line('       use Voxar\\AuthSdk\\Concerns\\HasVoxarAccount;');
        $this->line('  3. php artisan migrate');
        $this->line('  4. Supprimez les anciennes routes/pages login, register et mot de passe de l’application.');
        $this->newLine();
        $this->line('  Comptes existants ? php artisan voxar-auth:export-users, puis users:import sur Voxar Auth.');

        return self::SUCCESS;
    }

    private function publishMigration(): void
    {
        $existing = glob(database_path('migrations/*_add_voxar_auth_columns_to_users_table.php'));

        if ($existing !== []) {
            $this->components->warn('Migration déjà présente : '.basename($existing[0]));

            return;
        }

        $target = database_path('migrations/'.date('Y_m_d_His').'_add_voxar_auth_columns_to_users_table.php');
        File::copy(__DIR__.'/../../database/migrations/add_voxar_auth_columns_to_users_table.php.stub', $target);

        $this->components->info('Migration créée : '.basename($target));
    }

    private function writeEnvironment(): void
    {
        $path = base_path('.env');

        if (! File::exists($path)) {
            $this->components->warn('Pas de fichier .env : ajoutez VOXAR_AUTH_URL, VOXAR_AUTH_CLIENT et VOXAR_AUTH_TOKEN à la main.');

            return;
        }

        $env = File::get($path);
        $missing = array_filter([
            'VOXAR_AUTH_URL' => $this->option('url') ?: 'http://localhost:8002',
            'VOXAR_AUTH_CLIENT' => $this->option('client') ?: '',
            'VOXAR_AUTH_TOKEN' => '',
        ], fn ($value, $key) => ! preg_match("/^{$key}=/m", $env), ARRAY_FILTER_USE_BOTH);

        if ($missing === []) {
            return;
        }

        $lines = collect($missing)->map(fn ($value, $key) => "{$key}={$value}")->implode(PHP_EOL);
        File::append($path, PHP_EOL.'# Voxar Auth (connexion SSO)'.PHP_EOL.$lines.PHP_EOL);

        $this->components->info('Variables ajoutées au .env : '.implode(', ', array_keys($missing)));
    }
}

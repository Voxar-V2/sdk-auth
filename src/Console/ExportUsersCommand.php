<?php

namespace Voxar\AuthSdk\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Exports the accounts an application managed itself, for `php artisan users:import` on Voxar Auth.
 * Passwords stay the same (hashes are exported); two-factor secrets are decrypted with this
 * application's key and re-encrypted by Voxar Auth.
 */
class ExportUsersCommand extends Command
{
    protected $signature = 'voxar-auth:export-users
        {--path= : Fichier de sortie (défaut : storage/app/private/voxar-auth-export.json)}';

    protected $description = 'Exporte les comptes existants (mots de passe hachés, 2FA) pour Voxar Auth';

    public function handle(): int
    {
        if (! Schema::hasColumn('users', 'password')) {
            $this->components->error('La table users n’a plus de colonne password : rien à exporter.');

            return self::FAILURE;
        }

        $columns = Schema::getColumnListing('users');
        $value = fn ($user, string $column) => in_array($column, $columns, true) ? $user->{$column} : null;

        $rows = DB::table('users')->whereNotNull('password')->orderBy('id')->get()->map(fn ($user) => [
            'name' => $user->name,
            'email' => $user->email,
            'password' => $user->password,
            'email_verified_at' => $value($user, 'email_verified_at'),
            'two_factor_secret' => $this->decrypt($value($user, 'two_factor_secret')),
            'two_factor_recovery_codes' => $this->decrypt($value($user, 'two_factor_recovery_codes')),
            'two_factor_confirmed_at' => $value($user, 'two_factor_confirmed_at'),
            'created_at' => $value($user, 'created_at'),
        ])->all();

        $path = $this->option('path') ?: storage_path('app/private/voxar-auth-export.json');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->components->info(count($rows).' compte(s) exporté(s) dans '.$path);
        $this->line('  Sur Voxar Auth : php artisan users:import '.$path);
        $this->components->warn('Supprimez ce fichier après l’import : il contient les mots de passe hachés.');

        return self::SUCCESS;
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (Throwable) {
            return null;
        }
    }
}

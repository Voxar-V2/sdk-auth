<?php

namespace Voxar\AuthSdk\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Voxar\AuthSdk\Facades\VoxarAuth;
use Voxar\AuthSdk\Identity;

/**
 * For the application's User model: links a local user to their Voxar account.
 *
 * Expects the columns added by the SDK migration (php artisan voxar-auth:install): `auth_id` (uuid of
 * the Voxar account) and `two_factor_enabled`, plus the usual `name`, `email` and `email_verified_at`.
 * Override voxarAttributes() to map the identity differently.
 *
 * @mixin Model
 */
trait HasVoxarAccount
{
    public function initializeHasVoxarAccount(): void
    {
        $this->mergeCasts(['two_factor_enabled' => 'boolean']);
    }

    /**
     * The local user of a Voxar account: found by auth_id, or — for accounts created before Voxar Auth —
     * by e-mail address (Voxar Auth only hands over confirmed addresses), or created.
     */
    public static function findOrCreateFromVoxar(Identity $identity): static
    {
        return DB::transaction(function () use ($identity) {
            $user = static::query()->where('auth_id', $identity->id)->first()
                ?? static::query()->whereNull('auth_id')->where('email', strtolower($identity->email))->first()
                ?? new static;

            $user->syncFromVoxar($identity);

            return $user;
        });
    }

    /**
     * Copies the identity onto the local user (saved only when something changed).
     */
    public function syncFromVoxar(Identity $identity): void
    {
        $this->forceFill($this->voxarAttributes($identity));

        if (! $this->exists || $this->isDirty()) {
            $this->save();
        }
    }

    /**
     * Columns filled from the Voxar account.
     *
     * @return array<string, mixed>
     */
    protected function voxarAttributes(Identity $identity): array
    {
        return [
            'auth_id' => $identity->id,
            'name' => $identity->name,
            'email' => strtolower($identity->email),
            'email_verified_at' => $identity->emailVerified ? ($this->email_verified_at ?? now()) : null,
            'two_factor_enabled' => $identity->twoFactorEnabled,
        ];
    }

    /**
     * Fetches the account from Voxar Auth and updates the local copy. False when the account was deleted.
     */
    public function refreshFromVoxar(): bool
    {
        $identity = VoxarAuth::user($this->auth_id);

        if ($identity === null) {
            return false;
        }

        $this->syncFromVoxar($identity);

        return true;
    }

    /**
     * Changes the display name on Voxar Auth (shared by every application), then locally.
     */
    public function renameOnVoxar(string $name): void
    {
        $this->syncFromVoxar(VoxarAuth::rename($this->auth_id, $name));
    }

    /**
     * Deletes the Voxar account itself: every Voxar application loses access. Local data is kept.
     */
    public function deleteVoxarAccount(): void
    {
        VoxarAuth::deleteUser($this->auth_id);
    }

    /**
     * "Mon compte Voxar" page (profile, password, security, appearance), with a way back.
     */
    public function voxarAccountUrl(string $section = 'profile', ?string $returnTo = null): string
    {
        return VoxarAuth::accountUrl($section, $returnTo);
    }
}

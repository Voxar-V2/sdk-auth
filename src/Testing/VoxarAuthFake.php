<?php

namespace Voxar\AuthSdk\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Voxar\AuthSdk\Exceptions\VoxarAuthException;
use Voxar\AuthSdk\Identity;
use Voxar\AuthSdk\VoxarAuth;

/**
 * In-memory Voxar Auth for an application's tests: VoxarAuth::fake().
 */
class VoxarAuthFake extends VoxarAuth
{
    /** @var array<string, Identity> */
    private array $identities = [];

    /** @var array<string, string> code => identity id */
    private array $codes = [];

    /** @var list<array{0: string, 1: string}> */
    private array $renamed = [];

    /** @var list<string> */
    private array $deleted = [];

    private bool $unavailable = false;

    /**
     * Registers accounts known by the fake Voxar Auth.
     *
     * @param  Identity|Model|array<string, mixed>  ...$identities  an Identity, its array form, or a local user with auth_id
     */
    public function withIdentity(Identity|Model|array ...$identities): static
    {
        foreach ($identities as $identity) {
            $identity = $this->toIdentity($identity);
            $this->identities[$identity->id] = $identity;
        }

        return $this;
    }

    /**
     * A one-time code that signs this identity in through the callback route.
     *
     * @param  Identity|Model|array<string, mixed>  $identity
     */
    public function codeFor(Identity|Model|array $identity): string
    {
        $identity = $this->toIdentity($identity);
        $this->withIdentity($identity);

        $code = Str::random(40);
        $this->codes[$code] = $identity->id;

        return $code;
    }

    /**
     * Every call fails as if Voxar Auth could not be reached.
     */
    public function unavailable(): static
    {
        $this->unavailable = true;

        return $this;
    }

    public function exchangeCode(string $code, string $redirectUri): Identity
    {
        $this->ensureAvailable();

        $id = $this->codes[$code] ?? throw new VoxarAuthException('Code de connexion invalide ou expiré.', 422);
        unset($this->codes[$code]);

        return $this->identities[$id];
    }

    public function user(string $id): ?Identity
    {
        $this->ensureAvailable();

        return $this->identities[$id] ?? null;
    }

    public function findByEmail(string $email): ?Identity
    {
        $this->ensureAvailable();

        return collect($this->identities)->first(fn (Identity $identity) => strtolower($identity->email) === strtolower($email));
    }

    public function rename(string $id, string $name): Identity
    {
        $this->ensureAvailable();
        $identity = $this->identities[$id] ?? throw new VoxarAuthException("Compte Voxar inconnu : {$id}", 404);

        $this->renamed[] = [$id, $name];

        return $this->identities[$id] = Identity::fromArray([...$identity->toArray(), 'name' => $name]);
    }

    public function deleteUser(string $id): void
    {
        $this->ensureAvailable();

        $this->deleted[] = $id;
        unset($this->identities[$id]);
    }

    public function assertRenamed(string $id, ?string $name = null): void
    {
        PHPUnit::assertTrue(
            collect($this->renamed)->contains(fn ($call) => $call[0] === $id && ($name === null || $call[1] === $name)),
            "Le compte Voxar {$id} n’a pas été renommé".($name ? " en « {$name} »" : '').'.',
        );
    }

    public function assertNotRenamed(): void
    {
        PHPUnit::assertSame([], $this->renamed, 'Un compte Voxar a été renommé.');
    }

    public function assertDeleted(string $id): void
    {
        PHPUnit::assertContains($id, $this->deleted, "Le compte Voxar {$id} n’a pas été supprimé.");
    }

    public function assertNotDeleted(): void
    {
        PHPUnit::assertSame([], $this->deleted, 'Un compte Voxar a été supprimé.');
    }

    private function ensureAvailable(): void
    {
        if ($this->unavailable) {
            throw new VoxarAuthException('Voxar Auth est injoignable (simulé).');
        }
    }

    /**
     * @param  Identity|Model|array<string, mixed>  $identity
     */
    private function toIdentity(Identity|Model|array $identity): Identity
    {
        if ($identity instanceof Identity) {
            return $identity;
        }

        if ($identity instanceof Model) {
            return new Identity(
                id: (string) $identity->auth_id,
                name: (string) $identity->name,
                email: (string) $identity->email,
                emailVerified: $identity->email_verified_at !== null,
                twoFactorEnabled: (bool) $identity->two_factor_enabled,
            );
        }

        return Identity::fromArray(['id' => (string) Str::uuid(), 'email_verified' => true, ...$identity]);
    }
}

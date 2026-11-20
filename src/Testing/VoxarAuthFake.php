<?php

namespace Voxar\AuthSdk\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Voxar\AuthSdk\Exceptions\VoxarAuthException;
use Voxar\AuthSdk\Identity;
use Voxar\AuthSdk\OrganizationMembership;
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
     * Organizations by uuid: ['organization' => [...], 'units' => [uuid => [...]], 'members' => [uuid => [...]],
     * 'invitations' => [uuid => [...]]].
     *
     * @var array<string, array<string, mixed>>
     */
    private array $organizations = [];

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $organizationCalls = [];

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

        return isset($this->identities[$id]) ? $this->withMembership($this->identities[$id]) : null;
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

    /**
     * Seed an organization (directory shape returned by organization()).
     *
     * @param  array<string, mixed>  $directory
     */
    public function withOrganization(array $directory): static
    {
        $id = (string) $directory['organization']['id'];

        $this->organizations[$id] = [
            'organization' => ['suspended' => false, ...$directory['organization']],
            'units' => collect($directory['units'] ?? [])->keyBy('id')->all(),
            'members' => collect($directory['members'] ?? [])->keyBy('id')->all(),
            'invitations' => [],
        ];

        return $this;
    }

    public function organization(string $id): ?array
    {
        $this->ensureAvailable();

        return isset($this->organizations[$id]) ? $this->directory($id) : null;
    }

    public function organizationOf(string $userId): ?array
    {
        $this->ensureAvailable();
        $id = $this->organizationIdOf($userId);

        return $id === null ? null : $this->directory($id);
    }

    public function createOrganization(string $name, ?string $id = null, ?string $adminId = null): array
    {
        $this->record(__FUNCTION__, func_get_args());
        $id ??= (string) Str::uuid();
        $this->organizations[$id] = ['organization' => ['id' => $id, 'name' => $name, 'suspended' => false], 'units' => [], 'members' => [], 'invitations' => []];

        if ($adminId !== null) {
            $this->saveMember($id, $adminId, ['role' => 'admin']);
        }

        return $this->directory($id);
    }

    public function updateOrganization(string $id, array $changes): array
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->ensureOrganization($id);
        $this->organizations[$id]['organization'] = [...$this->organizations[$id]['organization'], ...array_intersect_key($changes, ['name' => 1, 'suspended' => 1])];

        return $this->directory($id);
    }

    public function deleteOrganization(string $id): void
    {
        $this->record(__FUNCTION__, func_get_args());
        unset($this->organizations[$id]);
    }

    public function createUnit(string $organizationId, array $attributes): array
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->ensureOrganization($organizationId);
        $unit = [
            'id' => $attributes['id'] ?? (string) Str::uuid(),
            'parent_id' => $attributes['parent_id'] ?? null,
            'name' => $attributes['name'],
            'manager_id' => $attributes['manager_id'] ?? null,
        ];

        return $this->organizations[$organizationId]['units'][$unit['id']] = $unit;
    }

    public function updateUnit(string $organizationId, string $unitId, array $changes): array
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->ensureOrganization($organizationId);
        $unit = $this->organizations[$organizationId]['units'][$unitId] ?? ['id' => $unitId, 'parent_id' => null, 'name' => '', 'manager_id' => null];

        return $this->organizations[$organizationId]['units'][$unitId] = [
            ...$unit,
            ...array_intersect_key($changes, ['name' => 1, 'parent_id' => 1, 'manager_id' => 1]),
        ];
    }

    public function deleteUnit(string $organizationId, string $unitId): void
    {
        $this->record(__FUNCTION__, func_get_args());

        if (! isset($this->organizations[$organizationId]['units'][$unitId])) {
            return;
        }

        $parentId = $this->organizations[$organizationId]['units'][$unitId]['parent_id'];
        unset($this->organizations[$organizationId]['units'][$unitId]);

        foreach ($this->organizations[$organizationId]['units'] as &$unit) {
            if ($unit['parent_id'] === $unitId) {
                $unit['parent_id'] = $parentId;
            }
        }

        foreach ($this->organizations[$organizationId]['members'] as &$member) {
            if (in_array($unitId, $member['unit_ids'], true)) {
                $member['unit_ids'] = array_values(array_unique(array_filter([
                    ...array_diff($member['unit_ids'], [$unitId]),
                    $parentId,
                ])));
            }
        }
    }

    public function saveMember(string $organizationId, string $userId, array $changes): Identity
    {
        $this->ensureAvailable();
        $this->record(__FUNCTION__, func_get_args());
        $this->ensureOrganization($organizationId);

        $current = $this->organizationIdOf($userId);

        if ($current !== null && $current !== $organizationId) {
            throw new VoxarAuthException('Voxar Auth a refusé la requête (422) : Ce compte appartient déjà à une autre organisation.', 422);
        }

        $identity = $this->identities[$userId] ?? null;
        $member = $this->organizations[$organizationId]['members'][$userId] ?? [
            'id' => $userId,
            'name' => $identity->name ?? '',
            'email' => $identity->email ?? '',
            'role' => 'member',
            'suspended' => false,
            'unit_ids' => [],
        ];

        $this->organizations[$organizationId]['members'][$userId] = [
            ...$member,
            ...array_intersect_key($changes, ['role' => 1, 'suspended' => 1, 'unit_ids' => 1]),
        ];

        return $this->withMembership($identity ?? Identity::fromArray([
            'id' => $userId,
            'name' => $member['name'],
            'email' => $member['email'],
            'email_verified' => true,
        ]));
    }

    public function removeMember(string $organizationId, string $userId): void
    {
        $this->record(__FUNCTION__, func_get_args());
        unset($this->organizations[$organizationId]['members'][$userId]);

        foreach ($this->organizations[$organizationId]['units'] ?? [] as $unitId => $unit) {
            if ($unit['manager_id'] === $userId) {
                $this->organizations[$organizationId]['units'][$unitId]['manager_id'] = null;
            }
        }
    }

    public function invitations(string $organizationId): array
    {
        $this->ensureAvailable();

        return array_values($this->organizations[$organizationId]['invitations'] ?? []);
    }

    public function invite(string $organizationId, array $attributes): array
    {
        $this->ensureAvailable();
        $this->record(__FUNCTION__, func_get_args());
        $this->ensureOrganization($organizationId);
        $email = strtolower(trim($attributes['email']));

        $existing = collect($this->organizations[$organizationId]['invitations'])->firstWhere('email', $email);
        $invitation = [
            'id' => $existing['id'] ?? (string) Str::uuid(),
            'email' => $email,
            'name' => $attributes['name'] ?? ($existing['name'] ?? null),
            'role' => $attributes['role'] ?? 'member',
            'unit_ids' => $attributes['unit_ids'] ?? [],
            'invited_by' => $attributes['invited_by'] ?? null,
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ];

        return $this->organizations[$organizationId]['invitations'][$invitation['id']] = $invitation;
    }

    public function resendInvitation(string $organizationId, string $invitationId): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->organizations[$organizationId]['invitations'][$invitationId]
            ?? throw new VoxarAuthException('Invitation inconnue.', 404);
    }

    public function revokeInvitation(string $organizationId, string $invitationId): void
    {
        $this->record(__FUNCTION__, func_get_args());
        unset($this->organizations[$organizationId]['invitations'][$invitationId]);
    }

    /**
     * Assert an organization change was sent to Voxar Auth, e.g.
     * assertOrganizationCall('saveMember', fn ($organizationId, $userId, $changes) => $changes['role'] === 'admin').
     */
    public function assertOrganizationCall(string $method, ?callable $matches = null): void
    {
        PHPUnit::assertTrue(
            collect($this->organizationCalls)->contains(fn ($call) => $call[0] === $method && ($matches === null || $matches(...$call[1]))),
            "Aucun appel « {$method} » correspondant n’a été envoyé à Voxar Auth.",
        );
    }

    public function assertNoOrganizationCalls(): void
    {
        PHPUnit::assertSame([], $this->organizationCalls, 'Des modifications d’organisation ont été envoyées à Voxar Auth.');
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function record(string $method, array $arguments): void
    {
        $this->ensureAvailable();
        $this->organizationCalls[] = [$method, $arguments];
    }

    /**
     * Applications may change an organization the fake was not seeded with: it is created on the fly.
     */
    private function ensureOrganization(string $id): void
    {
        $this->organizations[$id] ??= ['organization' => ['id' => $id, 'name' => '', 'suspended' => false], 'units' => [], 'members' => [], 'invitations' => []];
    }

    private function organizationIdOf(string $userId): ?string
    {
        foreach ($this->organizations as $id => $organization) {
            if (isset($organization['members'][$userId])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function directory(string $id): array
    {
        $organization = $this->organizations[$id];

        return [
            'organization' => $organization['organization'],
            'units' => array_values($organization['units']),
            'members' => array_values($organization['members']),
        ];
    }

    private function withMembership(Identity $identity): Identity
    {
        $organizationId = $this->organizationIdOf($identity->id);

        if ($organizationId === null) {
            return $identity;
        }

        $organization = $this->organizations[$organizationId];
        $member = $organization['members'][$identity->id];

        return Identity::fromArray([...$identity->toArray(), 'organization' => (new OrganizationMembership(
            id: $organizationId,
            name: (string) $organization['organization']['name'],
            role: (string) $member['role'],
            organizationSuspended: (bool) $organization['organization']['suspended'],
            memberSuspended: (bool) $member['suspended'],
            unitIds: $member['unit_ids'],
        ))->toArray()]);
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

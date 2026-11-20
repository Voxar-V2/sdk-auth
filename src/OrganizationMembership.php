<?php

namespace Voxar\AuthSdk;

/**
 * The organization a business account belongs to (personal accounts have none).
 */
final readonly class OrganizationMembership
{
    /**
     * @param  list<string>  $unitIds  uuids of the person's units
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $role,
        public bool $organizationSuspended = false,
        public bool $memberSuspended = false,
        public array $unitIds = [],
    ) {}

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'suspended' => $this->organizationSuspended,
            'role' => $this->role,
            'member_suspended' => $this->memberSuspended,
            'unit_ids' => $this->unitIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            role: (string) ($data['role'] ?? 'member'),
            organizationSuspended: (bool) ($data['suspended'] ?? false),
            memberSuspended: (bool) ($data['member_suspended'] ?? false),
            unitIds: array_values(array_map('strval', $data['unit_ids'] ?? [])),
        );
    }
}

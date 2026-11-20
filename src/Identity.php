<?php

namespace Voxar\AuthSdk;

/**
 * A person as known by Voxar Auth. `id` is the stable public identifier (uuid) to store locally.
 */
final readonly class Identity
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $emailVerified,
        public bool $twoFactorEnabled,
        public ?string $createdAt = null,
        public ?OrganizationMembership $organization = null,
    ) {}

    /**
     * Same shape as the API's "user" object.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'two_factor_enabled' => $this->twoFactorEnabled,
            'created_at' => $this->createdAt,
            'organization' => $this->organization?->toArray(),
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
            email: (string) $data['email'],
            emailVerified: (bool) ($data['email_verified'] ?? false),
            twoFactorEnabled: (bool) ($data['two_factor_enabled'] ?? false),
            createdAt: $data['created_at'] ?? null,
            organization: isset($data['organization']) ? OrganizationMembership::fromArray($data['organization']) : null,
        );
    }
}

<?php

namespace Voxar\AuthSdk\Facades;

use Illuminate\Support\Facades\Facade;
use Voxar\AuthSdk\Testing\VoxarAuthFake;

/**
 * @method static void resolveUsersUsing(\Closure $resolver)
 * @method static \Illuminate\Contracts\Auth\Authenticatable resolveUser(\Voxar\AuthSdk\Identity $identity)
 * @method static string authorizeUrl(string $redirectUri, string $state, array $options = [])
 * @method static string logoutUrl(?string $returnTo = null)
 * @method static string accountUrl(string $section = 'profile', ?string $returnTo = null)
 * @method static \Voxar\AuthSdk\Identity exchangeCode(string $code, string $redirectUri)
 * @method static \Voxar\AuthSdk\Identity|null user(string $id)
 * @method static \Voxar\AuthSdk\Identity|null findByEmail(string $email)
 * @method static \Voxar\AuthSdk\Identity rename(string $id, string $name)
 * @method static void deleteUser(string $id)
 * @method static array|null organization(string $id)
 * @method static array|null organizationOf(string $userId)
 * @method static array createOrganization(string $name, ?string $id = null, ?string $adminId = null)
 * @method static array updateOrganization(string $id, array $changes)
 * @method static void deleteOrganization(string $id)
 * @method static array createUnit(string $organizationId, array $attributes)
 * @method static array updateUnit(string $organizationId, string $unitId, array $changes)
 * @method static void deleteUnit(string $organizationId, string $unitId)
 * @method static \Voxar\AuthSdk\Identity saveMember(string $organizationId, string $userId, array $changes)
 * @method static void removeMember(string $organizationId, string $userId)
 * @method static array invitations(string $organizationId)
 * @method static array invite(string $organizationId, array $attributes)
 * @method static array resendInvitation(string $organizationId, string $invitationId)
 * @method static void revokeInvitation(string $organizationId, string $invitationId)
 * @method static void syncOnNextRequest()
 *
 * @see \Voxar\AuthSdk\VoxarAuth
 */
class VoxarAuth extends Facade
{
    /**
     * Replaces Voxar Auth with an in-memory fake for tests.
     */
    public static function fake(): VoxarAuthFake
    {
        return app(\Voxar\AuthSdk\VoxarAuth::class)->fake();
    }

    protected static function getFacadeAccessor(): string
    {
        return \Voxar\AuthSdk\VoxarAuth::class;
    }
}

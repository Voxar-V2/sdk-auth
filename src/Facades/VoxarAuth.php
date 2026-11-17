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

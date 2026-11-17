<?php

namespace Voxar\AuthSdk\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Voxar\AuthSdk\Exceptions\VoxarAuthException;

/**
 * Refreshes the signed-in person's name, address and two-factor state from Voxar Auth every
 * `voxar-auth.sync_every` seconds, and signs them out if their Voxar account was deleted.
 * Added to the "web" group automatically (config voxar-auth.sync_middleware).
 */
class SyncVoxarIdentity
{
    public const string SESSION_KEY = 'voxar-auth.synced_at';

    public function handle(Request $request, Closure $next): Response
    {
        $every = (int) config('voxar-auth.sync_every');
        $guard = Auth::guard(config('voxar-auth.guard'));
        $user = $guard->user();

        if ($every <= 0 || ! $request->hasSession() || ! $user || ! method_exists($user, 'refreshFromVoxar') || ! $user->auth_id) {
            return $next($request);
        }

        if (now()->timestamp - (int) $request->session()->get(self::SESSION_KEY, 0) < $every) {
            return $next($request);
        }

        try {
            $stillExists = $user->refreshFromVoxar();
        } catch (VoxarAuthException $exception) {
            // Voxar Auth unreachable: keep the session, try again on a later request.
            report($exception);

            return $next($request);
        }

        if (! $stillExists) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? abort(401) : redirect()->route('login');
        }

        $request->session()->put(self::SESSION_KEY, now()->timestamp);

        return $next($request);
    }
}

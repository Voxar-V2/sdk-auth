<?php

namespace Voxar\AuthSdk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Voxar\AuthSdk\Exceptions\VoxarAuthException;
use Voxar\AuthSdk\Http\Middleware\SyncVoxarIdentity;
use Voxar\AuthSdk\VoxarAuth;

/**
 * Sign-in and sign-out of the application, delegated to Voxar Auth.
 */
class SsoController extends Controller
{
    public function __construct(private readonly VoxarAuth $voxarAuth) {}

    /**
     * Sends the person to Voxar Auth. ?screen=register and ?email= are passed along (e.g. for invitations).
     */
    public function login(Request $request): Response
    {
        if (Auth::guard(config('voxar-auth.guard'))->check()) {
            return redirect()->intended(config('voxar-auth.home'));
        }

        // Opened at another address than APP_URL (e.g. 127.0.0.1 instead of localhost): start again from
        // APP_URL, the address whose /auth/callback is registered on Voxar Auth (and where the session lives).
        if (($canonical = $this->canonicalUrl($request)) !== null) {
            return redirect()->away($canonical);
        }

        $state = Str::random(40);
        $request->session()->put('voxar-auth.state', $state);

        return $this->away($request, $this->voxarAuth->authorizeUrl(route('voxar-auth.callback'), $state, array_filter([
            'screen' => $request->query('screen') === 'register' ? 'register' : null,
            'email' => is_string($request->query('email')) ? $request->query('email') : null,
        ])));
    }

    /**
     * Back from Voxar Auth with a one-time code: exchange it for the identity, then sign in locally.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('voxar-auth.state');
        $state = $request->query('state');

        // The state ties the answer to the sign-in started from this browser (login CSRF protection).
        abort_unless(is_string($expected) && is_string($state) && hash_equals($expected, $state), 403, 'La demande de connexion a expiré. Réessayez.');
        abort_unless(is_string($request->query('code')), 400, 'Code de connexion manquant.');

        try {
            $identity = $this->voxarAuth->exchangeCode($request->query('code'), route('voxar-auth.callback'));
        } catch (VoxarAuthException $exception) {
            report($exception);

            abort(502, 'La connexion via Voxar Auth a échoué. Réessayez dans un instant.');
        }

        $user = $this->voxarAuth->resolveUser($identity);

        Auth::guard(config('voxar-auth.guard'))->login($user);
        $request->session()->regenerate();
        // Just fetched from Voxar Auth: no need to refresh it on the next request.
        $request->session()->put(SyncVoxarIdentity::SESSION_KEY, now()->timestamp);

        return redirect()->intended(config('voxar-auth.home'));
    }

    /**
     * Signs out here and on Voxar Auth (single sign-out), then comes back to the application.
     */
    public function logout(Request $request): Response
    {
        Auth::guard(config('voxar-auth.guard'))->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->away($request, $this->voxarAuth->logoutUrl(url('/')));
    }

    /**
     * The same URL on APP_URL's scheme, host and port, or null when the request already uses them.
     */
    private function canonicalUrl(Request $request): ?string
    {
        $app = parse_url((string) config('app.url'));

        if (! isset($app['scheme'], $app['host'])) {
            return null;
        }

        $port = $app['port'] ?? ($app['scheme'] === 'https' ? 443 : 80);

        if ($request->getScheme() === $app['scheme']
            && strtolower($request->getHost()) === strtolower($app['host'])
            && $request->getPort() === $port) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').$request->getRequestUri();
    }

    /**
     * Leaves for Voxar Auth. Inertia visits are XHR requests that cannot follow a redirect to another
     * site: they get Inertia's "external visit" answer (409 + X-Inertia-Location) instead.
     */
    private function away(Request $request, string $url): Response
    {
        if ($request->hasHeader('X-Inertia')) {
            return response('', 409, ['X-Inertia-Location' => $url]);
        }

        return redirect()->away($url);
    }
}

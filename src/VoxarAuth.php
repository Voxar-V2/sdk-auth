<?php

namespace Voxar\AuthSdk;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Voxar\AuthSdk\Exceptions\VoxarAuthException;
use Voxar\AuthSdk\Http\Middleware\SyncVoxarIdentity;

/**
 * Client of the Voxar Auth server: SSO addresses and the server-to-server API.
 */
class VoxarAuth
{
    /** @var (Closure(Identity): Authenticatable)|null */
    protected ?Closure $userResolver = null;

    /**
     * @param  array{url: string, client: ?string, token: ?string, timeout?: int, user_model?: class-string}  $config
     */
    public function __construct(protected readonly array $config) {}

    /**
     * Replaces the default way of finding the local user (the User model's HasVoxarAccount trait).
     *
     * @param  Closure(Identity): Authenticatable  $resolver
     */
    public function resolveUsersUsing(Closure $resolver): void
    {
        $this->userResolver = $resolver;
    }

    /**
     * The local user for a signed-in identity: custom resolver, or `user_model::findOrCreateFromVoxar()`.
     */
    public function resolveUser(Identity $identity): Authenticatable
    {
        if ($this->userResolver !== null) {
            return ($this->userResolver)($identity);
        }

        $model = $this->config['user_model'] ?? null;

        if (! is_string($model) || ! method_exists($model, 'findOrCreateFromVoxar')) {
            throw new VoxarAuthException('Ajoutez le trait Voxar\AuthSdk\Concerns\HasVoxarAccount au modèle User (config voxar-auth.user_model), ou appelez VoxarAuth::resolveUsersUsing().');
        }

        return $model::findOrCreateFromVoxar($identity);
    }

    /**
     * Refreshes the signed-in identity on the next request (e.g. before sending the person to the
     * "Mon compte Voxar" pages, so that changes show up as soon as they are back).
     */
    public function syncOnNextRequest(): void
    {
        session()->forget(SyncVoxarIdentity::SESSION_KEY);
    }

    /**
     * Replaces Voxar Auth with an in-memory fake (tests).
     */
    public function fake(): Testing\VoxarAuthFake
    {
        $fake = new Testing\VoxarAuthFake($this->config);
        $fake->userResolver = $this->userResolver;

        app()->instance(self::class, $fake);
        Facades\VoxarAuth::clearResolvedInstance(self::class);

        return $fake;
    }

    /**
     * Sign-in page of Voxar Auth, which sends the person back to $redirectUri with a one-time code.
     *
     * @param  array{screen?: 'login'|'register', email?: string}  $options
     */
    public function authorizeUrl(string $redirectUri, string $state, array $options = []): string
    {
        return $this->url('sso/authorize', array_filter([
            'client' => $this->config['client'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'screen' => $options['screen'] ?? null,
            'email' => $options['email'] ?? null,
        ]));
    }

    /**
     * Signs out of every Voxar application, then goes to $returnTo.
     */
    public function logoutUrl(?string $returnTo = null): string
    {
        return $this->url('sso/logout', array_filter(['redirect_uri' => $returnTo]));
    }

    /**
     * "Mon compte Voxar" pages: profile, password, security (two-factor), appearance.
     */
    public function accountUrl(string $section = 'profile', ?string $returnTo = null): string
    {
        return $this->url('account/'.$section, array_filter(['return_to' => $returnTo]));
    }

    public function exchangeCode(string $code, string $redirectUri): Identity
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('api/sso/token', [
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]));

        return Identity::fromArray($this->json($response)['user']);
    }

    public function user(string $id): ?Identity
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('api/users/'.rawurlencode($id)));

        return $response->notFound() ? null : Identity::fromArray($this->json($response)['user']);
    }

    public function findByEmail(string $email): ?Identity
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('api/users/lookup', ['email' => $email]));

        return $response->notFound() ? null : Identity::fromArray($this->json($response)['user']);
    }

    /**
     * Changes the display name (the e-mail address can only be changed on Voxar Auth).
     */
    public function rename(string $id, string $name): Identity
    {
        $response = $this->send(fn (PendingRequest $http) => $http->patch('api/users/'.rawurlencode($id), ['name' => $name]));

        return Identity::fromArray($this->json($response)['user']);
    }

    /**
     * Deletes the Voxar account itself (every application loses access).
     */
    public function deleteUser(string $id): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete('api/users/'.rawurlencode($id)));

        if (! $response->notFound()) {
            $this->json($response);
        }
    }

    /**
     * @param  array<string, string>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = rtrim($this->config['url'], '/').'/'.$path;

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(Closure $call): Response
    {
        if (empty($this->config['token'])) {
            throw new VoxarAuthException('VOXAR_AUTH_TOKEN manquant : générez une clé avec « php artisan api:token » sur Voxar Auth.');
        }

        $http = Http::baseUrl(rtrim($this->config['url'], '/').'/')
            ->withToken($this->config['token'])
            ->acceptJson()
            ->timeout($this->config['timeout'] ?? 10);

        try {
            return $call($http);
        } catch (ConnectionException $exception) {
            throw new VoxarAuthException('Voxar Auth est injoignable : '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            throw new VoxarAuthException(
                'Voxar Auth a refusé la requête ('.$response->status().') : '.($response->json('message') ?? $response->body()),
                $response->status(),
            );
        }

        return $response->json() ?? [];
    }
}

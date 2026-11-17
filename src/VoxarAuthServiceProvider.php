<?php

namespace Voxar\AuthSdk;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Voxar\AuthSdk\Console\ExportUsersCommand;
use Voxar\AuthSdk\Console\InstallCommand;
use Voxar\AuthSdk\Http\Middleware\SyncVoxarIdentity;

class VoxarAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/voxar-auth.php', 'voxar-auth');

        $this->app->singleton(VoxarAuth::class, fn ($app) => new VoxarAuth($app['config']->get('voxar-auth')));
    }

    public function boot(Router $router): void
    {
        if ($this->app['config']->get('voxar-auth.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        $router->aliasMiddleware('voxar-auth.sync', SyncVoxarIdentity::class);

        if ($this->app['config']->get('voxar-auth.sync_middleware', true)) {
            // Through the HTTP kernel: it rewrites the router's groups when it is resolved.
            $append = fn ($kernel) => method_exists($kernel, 'appendMiddlewareToGroup')
                ? $kernel->appendMiddlewareToGroup('web', SyncVoxarIdentity::class)
                : $router->pushMiddlewareToGroup('web', SyncVoxarIdentity::class);

            $this->app->resolved(HttpKernel::class)
                ? $append($this->app->make(HttpKernel::class))
                : $this->app->afterResolving(HttpKernel::class, $append);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, ExportUsersCommand::class]);

            $this->publishes([
                __DIR__.'/../config/voxar-auth.php' => config_path('voxar-auth.php'),
            ], 'voxar-auth-config');
        }
    }
}

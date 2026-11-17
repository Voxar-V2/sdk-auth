<?php

return [

    /*
    | Address of the Voxar Auth server (e.g. https://auth.voxar.app).
    */
    'url' => env('VOXAR_AUTH_URL', 'http://localhost:8002'),

    /*
    | Client application name and API key, created on Voxar Auth with:
    |   php artisan api:token <email> --name=<client> --redirect=<APP_URL>/auth/callback
    */
    'client' => env('VOXAR_AUTH_CLIENT'),

    'token' => env('VOXAR_AUTH_TOKEN'),

    /*
    | Where people land after signing in, when no page was requested before.
    */
    'home' => env('VOXAR_AUTH_HOME', '/'),

    /*
    | Local user model. With the Voxar\AuthSdk\Concerns\HasVoxarAccount trait, users are found or created
    | automatically at sign-in (or call VoxarAuth::resolveUsersUsing() for custom logic).
    */
    'user_model' => env('VOXAR_AUTH_USER_MODEL', 'App\\Models\\User'),

    /*
    | Session guard used to sign people in locally.
    */
    'guard' => 'web',

    /*
    | Register the /login, /auth/callback and /logout routes (names: login, voxar-auth.callback, logout).
    */
    'routes' => true,

    /*
    | How often (in seconds) an application may refresh the signed-in identity from Voxar Auth.
    | 0 disables the refresh (handy in tests).
    */
    'sync_every' => (int) env('VOXAR_AUTH_SYNC_EVERY', 300),

    /*
    | Add the refresh middleware (Voxar\AuthSdk\Http\Middleware\SyncVoxarIdentity) to the "web" group.
    */
    'sync_middleware' => true,

    'timeout' => (int) env('VOXAR_AUTH_TIMEOUT', 10),

];

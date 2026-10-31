<?php

namespace App\Providers;

use App\Support\Permissions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Permissions::class);
    }

    public function boot(): void
    {
        // Reset emails link to the Angular page, not a Laravel route.
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));
    }
}

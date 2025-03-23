<?php
// app/Providers/FirebaseServiceProvider.php
namespace App\Providers;

use Kreait\Firebase\Factory;
use Illuminate\Support\ServiceProvider;

class FirebaseServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('firebase', function ($app) {
            return (new Factory)
                ->withServiceAccount(config('firebase.credentials'))
                ->createMessaging();
        });
    }
}

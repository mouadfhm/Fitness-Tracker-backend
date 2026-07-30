<?php
// app/Providers/FirebaseServiceProvider.php
namespace App\Providers;

use Kreait\Firebase\Factory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class FirebaseServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('firebase', function ($app) {
            $credentials = config('firebase.credentials');

            if (!$credentials || !is_file($credentials)) {
                throw new RuntimeException(
                    'Firebase service account not found at ' . ($credentials ?: '[not configured]') .
                    '. Set FIREBASE_CREDENTIALS to the service account JSON path.'
                );
            }

            return (new Factory)
                ->withServiceAccount($credentials)
                ->createMessaging();
        });
    }
}

<?php

use App\Http\Controllers\PrivacyPolicyController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

// Public, no auth: the store listings and the app's Settings link here.
// No session either, so reading the policy sets no cookie and writes nothing.
Route::get('/privacy', PrivacyPolicyController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->name('privacy');

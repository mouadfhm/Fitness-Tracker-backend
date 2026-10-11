<?php

namespace App\Http\Controllers;

class PrivacyPolicyController extends Controller
{
    /**
     * The public privacy policy the App Store and Google Play link to, and the
     * app opens from Settings. SetLocale has already picked English or French
     * from ?lang= or Accept-Language.
     */
    public function __invoke()
    {
        return view('privacy.show', [
            'contactEmail' => config('app.privacy_contact_email'),
        ]);
    }
}

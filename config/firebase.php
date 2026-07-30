<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Service Account
    |--------------------------------------------------------------------------
    |
    | Absolute path to the service account JSON generated in the Firebase
    | console (Project Settings -> Service Accounts -> Generate new private
    | key) for the "fitness-tracker-f1352" project. The file is mounted into
    | the container from the host rather than baked into the image, so it can
    | be rotated without a rebuild.
    |
    */

    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),

];

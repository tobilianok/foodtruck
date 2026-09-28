<?php

return [
    // Exemple : https://auth.louisrousseaux.fr/application/o/foodtruck/
    'issuer' => rtrim((string) env('OIDC_ISSUER', ''), '/').'/',
    'client_id' => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),
    'scopes' => env('OIDC_SCOPES', 'openid email profile'),
    'redirect' => rtrim((string) env('APP_URL'), '/').'/auth/callback',
];

<?php

$version = @file_get_contents(base_path('VERSION'));

return [
    'version' => $version !== false && trim($version) !== '' ? trim($version) : 'dev',

    // v0.18.0 : service interne foodtruck-pages (PDF ou photo → images pour le modèle de vision).
    'pages_url' => env('FOODTRUCK_PAGES_URL') ?: null,

    // v0.18.0 : lecture des fiches par un modèle de vision (Ollama sur le PC de Louis, RX 6800). Il lit seul : plus
    // aucun autre outil de reconnaissance de texte. Vide (développement, tests) : le texte de Paperless sert.
    'vision_url' => env('FOODTRUCK_VISION_URL') ?: null,
    'vision_model' => env('FOODTRUCK_VISION_MODEL') ?: 'qwen3-vl:8b-instruct-q8_0',
    'vision_dpi' => (int) (env('FOODTRUCK_VISION_DPI') ?: 200),
];

<?php

$version = @file_get_contents(base_path('VERSION'));

return [
    'version' => $version !== false && trim($version) !== '' ? trim($version) : 'dev',

    // Service interne de lecture des fiches (conteneur foodtruck-ocr). Vide : lecture désactivée, le texte de Paperless sert.
    'ocr_url' => env('FOODTRUCK_OCR_URL') ?: null,
];

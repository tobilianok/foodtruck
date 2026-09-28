<?php

$version = @file_get_contents(base_path('VERSION'));

return [
    'version' => $version !== false && trim($version) !== '' ? trim($version) : 'dev',
];

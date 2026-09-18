<?php

return [

    // спецификация лежит в репозитории рядом с backend/, в контейнер монтируется read-only
    'spec_path' => env('DOCS_SPEC_PATH', base_path('../docs/openapi.yaml')),

    'spec_url' => '/api/openapi.yaml',

    // UI документации грузится с CDN; хост попадает в CSP страницы /api/docs
    'scalar_cdn' => env('DOCS_SCALAR_CDN', 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.69.0'),

];

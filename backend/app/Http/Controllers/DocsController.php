<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Спецификация отдаётся как есть из файла, страница документации - Scalar с CDN.
 * Если CDN недоступен, страница показывает ссылку на сырой yaml, а не пустой экран.
 */
class DocsController extends Controller
{
    public function spec(): BinaryFileResponse
    {
        $path = (string) config('docs.spec_path');

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function ui(): Response
    {
        $spec = e((string) config('docs.spec_url'));
        $cdn = e((string) config('docs.scalar_cdn'));

        $html = <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BillingOS API</title>
<style>
  body { margin: 0; font-family: system-ui, sans-serif; }
  #fallback { display: none; padding: 48px 24px; max-width: 640px; margin: 0 auto; color: #1f2937; line-height: 1.5; }
  #fallback a { color: #0f766e; }
</style>
</head>
<body>
<div id="fallback">
  <h1>BillingOS API</h1>
  <p>Интерактивная документация не загрузилась (нет доступа к CDN).</p>
  <p>Спецификация OpenAPI доступна напрямую: <a href="{$spec}">{$spec}</a>.</p>
</div>
<script id="api-reference" data-url="{$spec}" data-configuration='{"theme":"default","darkMode":false,"withDefaultFonts":false}'></script>
<script src="{$cdn}" onerror="document.getElementById('fallback').style.display='block'"></script>
</body>
</html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }
}

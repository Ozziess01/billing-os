<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('serves the openapi spec and the docs page', function () {
    $this->get('/api/openapi.yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=utf-8');

    $this->get('/api/docs')->assertOk()
        ->assertSee('id="api-reference"', false)
        ->assertSee('data-url="/api/openapi.yaml"', false)
        ->assertHeader('Content-Security-Policy');

    // страница документации - единственное место, где CSP разрешает скрипты
    expect($this->get('/api/docs')->headers->get('Content-Security-Policy'))->toContain('script-src')
        ->and($this->getJson('/health')->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'none'");
});

it('documents every v1 route with its method', function () {
    $spec = Yaml::parseFile(config('docs.spec_path'));
    $documented = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }

    $undocumented = [];
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        $path = '/'.substr($route->uri(), strlen('api/v1/'));
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }
            if (! in_array("$method $path", $documented, true)) {
                $undocumented[] = "$method $path";
            }
        }
    }

    expect($undocumented)->toBe([]);

    // и наоборот: в спецификации нет выдуманных маршрутов
    $real = [];
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/')) {
            foreach ($route->methods() as $method) {
                $real[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
            }
        }
    }
    expect(array_values(array_diff($documented, $real)))->toBe([]);
});

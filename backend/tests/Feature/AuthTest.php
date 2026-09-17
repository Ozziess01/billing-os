<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('registers a user with an organization and returns a token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada', 'email' => 'ada@example.com',
        'password' => 'secret-password', 'password_confirmation' => 'secret-password',
        'organization' => 'Ada Corp',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['token', 'user' => ['id', 'email'], 'organizations'])
        ->assertJsonPath('organizations.0.name', 'Ada Corp')
        ->assertJsonPath('organizations.0.role', 'owner')
        ->assertJsonMissingPath('user.password');

    $this->withToken($response->json('token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('organizations.0.slug', 'ada-corp');
});

it('logs in, logs out and revokes only the current token', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertOk()->json('token');
    $second = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertOk()->json('token');

    $this->withToken($first)->postJson('/api/v1/auth/logout')->assertNoContent();

    // guard кэширует пользователя между запросами одного теста
    $this->app['auth']->forgetGuards();
    $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
});

it('rejects wrong credentials without telling which part is wrong and rate limits attempts', function () {
    RateLimiter::clear('login:ghost@example.com|127.0.0.1');
    $user = User::factory()->create(['email' => 'ghost@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Неверная почта или пароль.');
    }

    $blocked = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'password'])
        ->assertUnprocessable();
    expect($blocked->json('errors.email.0'))->toStartWith('Слишком много попыток');

    RateLimiter::clear('login:ghost@example.com|127.0.0.1');
    expect($user->tokens()->count())->toBe(0);
});

it('requires authentication for the api', function () {
    $this->getJson('/api/v1/customers')->assertUnauthorized();
    $this->getJson('/api/v1/organizations')->assertUnauthorized();
});

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, OrganizationService $organizations): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        if ($request->filled('organization')) {
            $organizations->create($user, $request->string('organization')->toString());
        }

        return $this->issueToken($user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        // 5 попыток на пару почта+ip, дальше пауза: перебор паролей упирается сюда
        $key = 'login:'.Str::lower($request->string('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Слишком много попыток входа. Попробуйте через '.RateLimiter::availableIn($key).' с.',
            ]);
        }

        $user = User::query()->where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['email' => 'Неверная почта или пароль.']);
        }

        RateLimiter::clear($key);

        return $this->issueToken($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('organizations');

        return response()->json([
            'data' => new UserResource($user),
            'organizations' => OrganizationResource::collection($user->organizations),
        ]);
    }

    private function issueToken(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('session', ['*'], now()->addDays(30));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
            'organizations' => OrganizationResource::collection($user->organizations()->get()),
        ], $status);
    }
}

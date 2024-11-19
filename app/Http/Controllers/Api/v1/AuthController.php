<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Handle user login
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'email' => 'required|string|email',
                'username' => 'required|string',
                'password' => 'required|string'
            ]);
        } catch (ValidationException $e) {
            return $this->error(
                'Invalid credentials',
                422,
                ['email' => ['These credentials do not match our records']]
            );
        }

        // Find user by email and username
        $user = User::query()->where('email', $validatedData['email'])
            ->where('username', $validatedData['username'])
            ->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($validatedData['password'], $user->password)) {
            return $this->error(
                'Invalid credentials',
                401,
                ['email' => ['These credentials do not match our records']]
            );
        }

        // Get user profile using relationship
        $profile = $user->profile;

        // Update user's last login timestamp
        $user->touch();

        return $this->success([
            'id' => $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'profile' => [
                'firstName' => $profile?->firstName,
                'lastName' => $profile?->lastName,
                'avatar' => $profile?->avatar_url,
                'totalCoins' => $profile?->current_coins,
            ],
            'token' => [
                'accessToken' => $user->tokens()
                    ->where('name', "UserAccessToken.{$user->id}")
                    ->first()?->plainTextToken,
            ],
        ]);
    }

    /**
     * Handle user registration
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        // Validate email first
        $emailValidation = self::emailValidator($request->json('email'));
        if (!$emailValidation['valid']) {
            return $this->error(
                'Email validation failed',
                422,
                ['email' => [$emailValidation['message']]]
            );
        }

        try {
            $validatedData = $request->validate([
                'email' => 'required|string|email|max:255|unique:users',
                'username' => 'required|string|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);
        } catch (ValidationException $e) {
            return $this->error(
                'Validation error',
                422,
                $e->errors()
            );
        }

        try {
            // Create new user
            $user = User::query()->create([
                'email' => $validatedData['email'],
                'username' => $validatedData['username'],
                'password' => Hash::make($validatedData['password']),
            ]);

            // Create access token
            $token = $user->createToken("UserAccessToken.{$user->id}")->plainTextToken;

            return $this->success([
                'id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
                'profile' => [
                    'firstname' => null,
                    'lastname' => null,
                    'avatar' => null,
                ],
                'token' => [
                    'accessToken' => $token,
                ],
            ]);

        } catch (\Exception $e) {
            report($e); // Log the error
            return $this->error('Server error while registering', 500);
        }
    }

    /**
     * Validate email address
     *
     * @param string $email
     * @return array
     */
    private static function emailValidator(string $email): array
    {
        try {
            // Basic validation
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid email format'
                ];
            }

            $domain = substr(strrchr($email, "@"), 1);

            // DNS check with caching
            $dnsValid = cache()->remember(
                "dns_check_{$domain}",
                3600,
                fn() => checkdnsrr($domain, "MX")
            );

            if (!$dnsValid) {
                return [
                    'valid' => false,
                    'message' => 'Domain does not have valid mail servers'
                ];
            }

            // Check for disposable email domains
            $disposableDomains = config('services.disposable_domains', [
                'tempmail.com',
                'throwaway.com'
            ]);

            if (in_array($domain, $disposableDomains)) {
                return [
                    'valid' => false,
                    'message' => 'Disposable email addresses are not allowed'
                ];
            }

            // Only perform API validation in production
            if (app()->environment('production')) {
                $response = Http::timeout(5)
                    ->get("https://api.emailvalidation.io/v1/info", [
                        'apikey' => config('services.email_validator.key'),
                        'email' => $email
                    ]);

                if ($response->successful()) {
                    $result = $response->json();

                    if (!$result['smtp_check']) {
                        return [
                            'valid' => false,
                            'message' => 'Email address does not exist'
                        ];
                    }

                    return [
                        'valid' => true,
                        'metadata' => [
                            'business_email' => $result['is_business_email'] ?? false,
                            'domain_age' => $result['domain_age'] ?? null,
                            'provider' => $result['provider'] ?? null
                        ]
                    ];
                }
            }

            return ['valid' => true];

        } catch (\Exception $e) {
            report($e); // Log any errors
            return ['valid' => true]; // Fail open on errors
        }
    }
}

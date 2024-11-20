<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCodeMail;
use App\Models\v1\User;
use App\Models\v1\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    use ApiResponse;

    public function register(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'email' => 'required|string|email|max:255|unique:users',
                'username' => 'required|string|max:255|unique:users',
                'password' => 'required|string|min:8',
            ]);

            try {
                DB::beginTransaction();

                // User yaratish (verified = false)
                $user = User::create([
                    'email' => $validatedData['email'],
                    'username' => $validatedData['username'],
                    'password_hash' => Hash::make($validatedData['password']),
                    'status' => 'pending', // yoki is_verified = false
                ]);

                // Verification code yaratish
                $code = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);

                VerificationCode::create([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'code' => $code,
                    'type' => 'email_verification',
                    'expires_at' => now()->addMinutes(10)
                ]);

                // Verification code yuborish
                Mail::to($user->email)->send(new VerificationCodeMail(
                    $code,
                    'email_verification',
                    $user
                ));

                DB::commit();

                return $this->success([
                    'message' => 'Registration successful. Please verify your email',
                    'email' => $user->email,
                    'expires_at' => now()->addMinutes(10)
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Registration error', ['error' => $e->getMessage()]);
            return $this->error('Server error while registering', 500);
        }
    }

    public function verifyEmail(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'code' => 'required|string|size:6'
            ]);

            if ($validator->fails()) {
                return $this->error('Validation error', 422, $validator->errors());
            }

            $user = User::where('email', $request->email)
                ->where('status', 'pending')
                ->first();

            if (!$user) {
                return $this->error(
                    'Invalid user',
                    404,
                    ['email' => ['User not found or already verified']]
                );
            }

            // Verification codeni tekshirish
            $verification = VerificationCode::where('user_id', $user->id)
                ->where('code', $request->code)
                ->where('type', 'email_verification')
                ->where('is_used', false)
                ->where('expires_at', '>', now())
                ->first();

            if (!$verification) {
                return $this->error(
                    'Invalid code',
                    422,
                    ['code' => ['Invalid or expired verification code']]
                );
            }

            try {
                DB::beginTransaction();

                // Verification code ishlatildi deb belgilash
                $verification->update(['is_used' => true]);

                // User statusini active qilish
                $user->update([
                    'status' => 'active',
                    'email_verified_at' => now()
                ]);

                // Access token yaratish
                $token = $user->createToken("UserAccessToken.{$user->id}")->plainTextToken;

                DB::commit();

                return $this->success([
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'profile' => [
                        'firstname' => null,
                        'lastname' => null,
                        'avatar' => null,
                        'totalCoins' => null,
                    ],
                    'token' => [
                        'accessToken' => $token,
                    ],
                    'message' => 'Email verified successfully'
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Email verification error', ['error' => $e->getMessage()]);
            return $this->error('Server error', 500);
        }
    }

    public function login(Request $request): \Illuminate\Http\JsonResponse
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
        if (!$user || !Hash::check($validatedData['password'], $user->password_hash)) {
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
        $user->tokens()->where('name', "UserAccessToken.{$user->id}")->delete();
        $token = $user->createToken("UserAccessToken.{$user->id}")->plainTextToken;

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
                'accessToken' => $token,
            ],
        ]);
    }

    public function logout(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return $this->error(
                    'Token not provided',
                    401,
                    ['token' => ['Bearer token is required']]
                );
            }

            $tokenRecord = PersonalAccessToken::findToken($token);

            if (!$tokenRecord) {
                return $this->error(
                    'Invalid token',
                    401,
                    ['token' => ['Token is invalid or expired']]
                );
            }

            $user = $tokenRecord->tokenable()->first();

            if (!$user) {
                return $this->error(
                    'User not found',
                    404,
                    ['user' => ['User associated with an invalid token']]
                );
            }

            $userProfile = $user->profile;

            try {
                DB::beginTransaction();

                $user->update([
                    'status' => 'deleted'
                ]);

                $tokenRecord->delete();

                DB::commit();

                return $this->success([
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                    'profile' => [
                        'firstName' => $userProfile?->first_name,
                        'lastName' => $userProfile?->last_name,
                        'avatar' => $userProfile?->avatar_url,
                        'totalCoins' => $userProfile?->total_coins,
                    ],
                    'status' => $user->status,
                    'message' => "Successfully logged out",
                ]);
            } catch (\Exception $e) {
                DB::rollBack();

                Log::error('Error during logout process', [
                    'userId' => $user->id,
                    'tokenId' => $tokenRecord->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return $this->error(
                    'Error during logout',
                    500,
                    ['general' => ['Could not complete logout process']]
                );
            }
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('General logout error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->error(
                'Server error',
                500,
                ['general' => ['An unexpected error occurred']]
            );
        }
    }

    public function requestEmailChange(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            // Validate
            $validator = Validator::make($request->all(), [
                'oldEmail' => 'required|email',
                'newEmail' => 'required|email|different:oldEmail|unique:users,email'
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation error',
                    422,
                    $validator->errors()
                );
            }

            // Check old email
            $user = auth()->user();
            if ($request->oldEmail !== $user->email) {
                return $this->error(
                    'Invalid email',
                    422,
                    ['oldEmail' => ['Current email is incorrect']]
                );
            }

            try {
                DB::beginTransaction();

                // Delete old codes
                VerificationCode::where('user_id', $user->id)
                    ->where('type', 'email_change')
                    ->delete();

                // Generate new code
                $code = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);

                // Save verification
                $verification = VerificationCode::create([
                    'user_id' => $user->id,
                    'email' => $request->newEmail, // Send to new email
                    'code' => $code,
                    'type' => 'email_change',
                    'data' => [
                        'new_email' => $request->newEmail,
                        'old_email' => $request->oldEmail
                    ],
                    'expires_at' => now()->addMinutes(10)
                ]);

                // Send email to NEW email address
                Mail::to($request->newEmail)->send(new VerificationCodeMail(
                    $code,
                    'email_change',
                    $user
                ));

                DB::commit();

                return $this->success([
                    'message' => 'Verification code sent to new email',
                    'expires_at' => $verification->expires_at
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Email change request error', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
                return $this->error('Failed to send verification code', 500);
            }

        } catch (\Exception $e) {
            Log::error('Email change error', ['error' => $e->getMessage()]);
            return $this->error('Server error', 500);
        }
    }

    public function verifyEmailChange(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|size:6'
            ]);

            if ($validator->fails()) {
                return $this->error('Validation error', 422, $validator->errors());
            }

            $user = auth()->user();

            // Find valid code
            $verification = VerificationCode::where('user_id', $user->id)
                ->where('code', $request->code)
                ->where('type', 'email_change')
                ->where('is_used', false)
                ->where('expires_at', '>', now())
                ->first();

            if (!$verification) {
                return $this->error(
                    'Invalid code',
                    422,
                    ['code' => ['Invalid or expired verification code']]
                );
            }

            try {
                DB::beginTransaction();

                // Mark as used
                $verification->update(['is_used' => true]);

                // Update email
                $user->update([
                    'email' => $verification->data['new_email']
                ]);

                DB::commit();

                return $this->success([
                    'message' => 'Email changed successfully',
                    'email' => $user->email
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Email verification error', ['error' => $e->getMessage()]);
                return $this->error('Failed to change email', 500);
            }
        } catch (\Exception $e) {
            Log::error('Email verification error', ['error' => $e->getMessage()]);
            return $this->error('Server error', 500);
        }
    }

    public function requestPasswordReset(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email'
            ]);

            if ($validator->fails()) {
                return $this->error('Validation error', 422, $validator->errors());
            }

            $user = User::where('email', $request->email)->first();

            try {
                DB::beginTransaction();

                // O'chirish eski kodlarni
                VerificationCode::where('user_id', $user->id)
                    ->where('type', 'password_reset')
                    ->delete();

                // Yaratish yangi kod
                $code = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);

                // Saqlash
                $verification = VerificationCode::create([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'code' => $code,
                    'type' => 'password_reset',
                    'expires_at' => now()->addMinutes(10)
                ]);

                // Yuborish email
                Mail::to($user->email)->send(new VerificationCodeMail(
                    $code,
                    'password_reset',
                    $user
                ));

                DB::commit();

                return $this->success([
                    'message' => 'Password reset code sent to your email',
                    'expires_at' => $verification->expires_at
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Password reset request error', ['error' => $e->getMessage()]);
            return $this->error('Server error', 500);
        }
    }

    public function verifyResetCode(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'code' => 'required|string|size:6'
            ]);

            if ($validator->fails()) {
                return $this->error('Validation error', 422, $validator->errors());
            }

            $user = User::where('email', $request->email)->first();

            // Tekshirish kodni
            $verification = VerificationCode::where('user_id', $user->id)
                ->where('code', $request->code)
                ->where('type', 'password_reset')
                ->where('is_used', false)
                ->where('expires_at', '>', now())
                ->first();

            if (!$verification) {
                return $this->error(
                    'Invalid code',
                    422,
                    ['code' => ['Invalid or expired verification code']]
                );
            }

            return $this->success([
                'message' => 'Code verified successfully',
                'reset_token' => $verification->id // Keyingi so'rov uchun token
            ]);

        } catch (\Exception $e) {
            Log::error('Code verification error', ['error' => $e->getMessage()]);
            return $this->error('Server error', 500);
        }
    }

    public function resetPassword(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reset_token' => 'required|string|exists:verification_codes,id',
                'newPassword' => 'required|string|min:8',
                'confirmPassword' => 'required|same:newPassword'
            ]);

            if ($validator->fails()) {
                return $this->error('Validation error', 422, $validator->errors());
            }

            $verification = VerificationCode::where('id', $request->reset_token)
                ->where('type', 'password_reset')
                ->where('is_used', false)
                ->where('expires_at', '>', now())
                ->first();

            if (!$verification) {
                return $this->error(
                    'Invalid reset token',
                    422,
                    ['reset_token' => ['Invalid or expired reset token']]
                );
            }

            $user = User::find($verification->user_id);

            try {
                DB::beginTransaction();

                // Belgilash kodni ishlatilgan deb
                $verification->update(['is_used' => true]);

                // Yangilash parolni
                $user->update([
                    'password_hash' => Hash::make($request->newPassword)
                ]);

                // O'chirish barcha tokenlarni
                $user->tokens()->delete();

                DB::commit();

                return $this->success([
                    'message' => 'Password has been reset successfully'
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Password reset error', ['error' => $e->getMessage()]);
            return $this->error('Server error', 500);
        }
    }

    public function changePassword(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'oldPassword' => 'required|string',
                'newPassword' => 'required|string|min:8|different:oldPassword'
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation error',
                    422,
                    $validator->errors()
                );
            }

            $user = auth()->user();

            if (!Hash::check($request->oldPassword, $user->password_hash)) {
                return $this->error(
                    'Invalid password',
                    422,
                    ['oldPassword' => ['Current password is incorrect']]
                );
            }

            try {
                DB::beginTransaction();

                $user->update([
                    'password_hash' => Hash::make($request->newPassword)
                ]);

                DB::commit();

                return $this->success([
                    'message' => 'Password changed successfully'
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Password change error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return $this->error('Server error', 500);
        }
    }

}

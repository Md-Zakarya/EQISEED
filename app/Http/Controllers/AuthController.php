<?php
// app/Http/Controllers/AuthController.php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{   
    protected function unauthenticated($request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        return redirect()->route('login');
    }
    public function getUserProfile()
{
    $user = auth()->user();
    $latestRound = $user->rounds()->latest()->first();
    $totalSharesAvailable = $latestRound ? $latestRound->current_valuation / (1 - ($latestRound->shares_diluted / 100)) : 0;
    
    return response()->json([
        'success' => true,
        'data' => [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'company_name' => $user->company_name,
            'company_role' => $user->company_role,
            'linkedin_url' => $user->linkedin_url,
            'has_experience' => $user->has_experience,
            'sectors' => $user->sectors,
            'rounds' => $user->rounds,
            'current_valuation' => $latestRound ? $latestRound->current_valuation : null,
            'total_shares_available' => $totalSharesAvailable,
            'phone_number' => $user->phone,
        ]
    ], 200);
}
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'hasExperience' => 'required|boolean',
            'sectors' => 'required|array',
            'rounds' => 'nullable|array',
            'linkedInUrl' => 'nullable|url',
            'phone' => 'required|string|unique:users',
            'countryCode' => 'required|string',
            'companyName' => 'nullable|string|max:255',
            'companyRole' => 'nullable|string|max:255',
            'userType' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = User::create([
            'first_name' => $request->firstName,
            'last_name' => $request->lastName,
            'email' => $request->email,
            'has_experience' => $request->hasExperience,
            'sectors' => $request->sectors,
            'rounds' => $request->rounds,
            'linkedin_url' => $request->linkedInUrl,
            'phone' => $request->phone,
            'country_code' => $request->countryCode,
            'company_name' => $request->companyName,
            'company_role' => $request->companyRole,
            'user_type' => $request->userType,
            'password' => Hash::make('password'), // Set a default password or handle accordingly
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }
    public function login(Request $request)
    {
        Log::info('Login request received', ['phone' => $request->phone, 'countryCode' => $request->countryCode]);

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'countryCode' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::warning('Login validation failed', ['errors' => $validator->errors()]);
            return response()->json($validator->errors(), 422);
        }

        // Send OTP
        $otpController = new OTPController();
        $response = $otpController->sendOTP($request);

        Log::info('OTP sent', context: ['phone' => $request->phone, 'response' => $response]);

        return $response;
    }

    public function verifyLoginOTP(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'otp' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Verify OTP
        $otpController = new OTPController();
        $response = $otpController->verifyOTP($request);

        if ($response->getStatusCode() == 200) {
            $user = User::where('phone', $request->phone)->first();
            if ($user) {
                $token = $user->createToken('auth_token')->plainTextToken;
                return response()->json(['message' => 'Login successful', 'token' => $token, 'token_type' => 'Bearer'], 200);
            } else {
                return response()->json(['message' => 'User not found'], 404);
            }
        }

        return $response;
    }


    public function redirectToLinkedIn()
    {
        // Validate LinkedIn configuration
        if (!config('services.linkedin.client_id') || !config('services.linkedin.client_secret')) {
            Log::error('LinkedIn configuration missing');
            return response()->json(['error' => 'LinkedIn configuration not found'], 500);
        }

        return Socialite::driver('linkedin')
            ->scopes(['r_liteprofile', 'r_emailaddress', 'w_member_social'])
            ->redirect();
    }
    public function handleLinkedInCallback(Request $request)
    {
        try {
            if (!$request->has('code')) {
                Log::error('LinkedIn callback missing code parameter.', ['request' => $request->all()]);
                return response()->json(['error' => 'Missing code parameter'], 400);
            }

            Log::info('LinkedIn callback initiated.');
            $linkedinUser = Socialite::driver('linkedin')->user();
            Log::info('LinkedIn user retrieved.', ['user' => $linkedinUser]);

            // Find existing user or create new
            $user = User::where('email', $linkedinUser->email)->first();

            if (!$user) {
                $names = explode(' ', $linkedinUser->name);
                $firstName = $names[0];
                $lastName = isset($names[1]) ? $names[1] : '';

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $linkedinUser->email,
                    'linkedin_url' => $linkedinUser->profileUrl,
                    'avatar' => $linkedinUser->avatar ?? null,
                ]);
                Log::info('New user created.', ['user' => $user]);
            } else {
                // Update existing user's LinkedIn info
                $user->update([
                    'linkedin_url' => $linkedinUser->profileUrl,
                    'avatar' => $linkedinUser->avatar ?? $user->avatar,
                ]);
                Log::info('Existing user updated.', ['user' => $user]);
            }

            // Generate token
            $token = $user->createToken('linkedin-token')->plainTextToken;
            Log::info('Token generated.', ['token' => $token]);

            return response()->json([
                'status' => 'success',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'linkedin_url' => $user->linkedin_url,
                    'avatar' => $user->avatar
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('LinkedIn authentication failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Authentication failed'], 500);
        }
    }

}
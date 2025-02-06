<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function login(Request $request)
{
    try {
        // Input validation
        $credentials = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|min:6'
        ]);

        // // Check if user is already logged in
        // if (Auth::check()) {
        //     return response()->json([
        //         'message' => 'User is already logged in'
        //     ], 400);
        // }

        // Attempt authentication
        if (!Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 404);
        }

        $user = Auth::user();

        // Check if user account is active
        // if (!$user->is_active) {
        //     Auth::logout();
        //     return response()->json([
        //         'message' => 'Account is deactivated'
        //     ], 403);
        // }

        // Verify admin role
        if (!$user->hasRole('admin')) {
            Auth::logout();
            return response()->json([
                'message' => 'Unauthorized. Admin access only.'
            ], 403);
        }

        // Generate token and return response
        $token = $user->createToken('admin-token')->plainTextToken;
        
        return response()->json([
            'token' => $token,
           
            'message' => 'Admin logged in successfully'
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Server error occurred',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    public function checkStatus()
    {
        return response()->json(['message' => 'Admin route is working!']);
    }
}
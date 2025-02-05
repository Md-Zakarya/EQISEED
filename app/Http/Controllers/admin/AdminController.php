<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            if ($user->hasRole('admin')) {
                $token = $user->createToken('admin-token')->plainTextToken;
                return response()->json([
                    'token' => $token,
                    'user' => $user,
                    'message' => 'Admin logged in successfully'
                ], 200);
            }
            return response()->json(['message' => 'Unauthorized. Admin access only.'], 403);
        }

        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    public function checkStatus()
    {
        return response()->json(['message' => 'Admin route is working!']);
    }
}
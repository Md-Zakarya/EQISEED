<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OTP;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class OTPController extends Controller
{
    public function sendOTP(Request $request)
{
    $request->validate([
        'phone' => 'required|string',
        'countryCode' => 'required|string',
    ]);

    $phone = $request->input('phone');
    $countryCode = $request->input('countryCode');

    // Generate OTP
    // $otp = rand(100000, 999999);
    $otp = 123456;

    // Save OTP to the database
    OTP::create([
        'phone' => $phone,
        'country_code' => $countryCode,
        'otp' => $otp,
    ]);

    Log::info('OTP generated and saved', ['phone' => $phone, 'countryCode' => $countryCode, 'otp' => $otp]);

    // Here you would send the OTP to the user via SMS
    // For example, using a third-party service like Twilio
    // Http::post('https://api.twilio.com/send', [
    //     'to' => $countryCode . $phone,
    //     'message' => 'Your OTP is ' . $otp,
    // ]);

    return response()->json(['message' => 'OTP sent successfully']);
}

public function verifyOTP(Request $request)
{
    $request->validate([
        'phone' => 'required|string',
        'otp' => 'required|integer',
    ]);

    $phone = $request->input('phone');
    $otp = $request->input('otp');

    Log::info('OTP verification requested', ['phone' => $phone, 'otp' => $otp]);

    // Check if OTP is valid
    $otpRecord = OTP::where('phone', $phone)
        ->where('otp', $otp)
        ->first();

    if ($otpRecord) {
        // OTP is valid, delete it from the database
        $otpRecord->delete();

        Log::info('OTP verified successfully', ['phone' => $phone]);

        return response()->json(['message' => 'OTP verified successfully']);
    } else {
        Log::warning('Invalid OTP attempt', ['phone' => $phone, 'otp' => $otp]);

        return response()->json(['message' => 'Invalid OTP'], 400);
    }
}
}
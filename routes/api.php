<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OTPController;
use App\Http\Controllers\AuthController;
use Illuminate\Session\Middleware\StartSession;
use App\Http\Controllers\FundingController;
use App\Http\Controllers\FundingInvestorController;

Route::middleware([StartSession::class])->group(function () {
    Route::post('/send-otp', [OTPController::class, 'sendOTP']);
    Route::post('/verify-otp', [OTPController::class, 'verifyOTP']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-login-otp', [AuthController::class, 'verifyLoginOTP']);
    Route::get('/auth/linkedin', [AuthController::class, 'redirectToLinkedIn']);
    Route::get('/auth/linkedin/callback', [AuthController::class, 'handleLinkedInCallback']);
    // Route::post('/funding-rounds', [FundingController::class, 'store']);
    

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/funding/store-bulk', [FundingController::class, 'storeBulk']);
    Route::get('/funding/rounds', [FundingController::class, 'getUserRounds']);
    Route::get('/test', function() {
        return response()->json(['message' => 'If you see this, you are authenticated!']);
    });
    Route::post('/funding/new-round', [FundingController::class, 'newRound']);

   
       Route::get('/getUserDetails', [AuthController::class, 'getUserProfile']);

        

       Route::get('/funding/new-rounds', [FundingController::class, 'getNewRounds']);



       //round approval and activation routes
       Route::patch('/funding-rounds/{fundingRound}/approve', [FundingController::class, 'approveRound']);
       Route::patch('/funding-rounds/{fundingRound}/activate', [FundingController::class, 'activateRound']);
       Route::patch('/funding-rounds/{fundingRound}/close', [FundingController::class, 'closeRound']);


       //invester funding simulation
       Route::post('/funding-investors', [FundingInvestorController::class, 'store']);

});






});

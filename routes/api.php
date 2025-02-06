<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OTPController;
use App\Http\Controllers\AuthController;
use Illuminate\Session\Middleware\StartSession;
use App\Http\Controllers\FundingController;
use App\Http\Controllers\FundingInvestorController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminFundingController;

// Route::middleware([StartSession::class])->group(function () {
    Route::post('/send-otp', [OTPController::class, 'sendOTP']);
    Route::post('/verify-otp', [OTPController::class, 'verifyOTP']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-login-otp', [AuthController::class, 'verifyLoginOTP']);
    Route::get('/auth/linkedin', [AuthController::class, 'redirectToLinkedIn']);
    Route::get('/auth/linkedin/callback', [AuthController::class, 'handleLinkedInCallback']);

    Route::get('/rounds', function() {
        $rounds = \App\Models\PredefinedRound::orderBy('sequence')
            ->select('id', 'name', 'sequence')
            ->orderBy('sequence')
            ->get();
        return response()->json([
            'success' => true,
            'data' => $rounds
        ]);
    });
    
    Route::get('/sectors', function() {
        $sectors = \App\Models\PredefinedSector::orderBy('name')
            ->select('id', 'name')
            ->get();
        return response()->json([
            'success' => true,
            'data' => $sectors
        ]);
    });

//admin login route

    Route::post('/admin/login', [AdminController::class, 'login']);
    // Route::post('/funding-rounds', [FundingController::class, 'store']);
    

Route::middleware(['auth:sanctum', 'role:user'])->group(function () {
    Route::post('/funding/store-bulk', [FundingController::class, 'storeBulk']);
    Route::get('/funding/round-overview', [FundingController::class, 'getUserRounds']);
    Route::get('/test', function() {
        return response()->json(['message' => 'If you see this, you are authenticated!']);
    });
    Route::post('/funding/new-round', [FundingController::class, 'newRound']);

   
       Route::get('/getUserDetails', [AuthController::class, 'getUserProfile']);

        

       Route::get('/funding/get-current-valuation', [FundingController::class, 'getLatestCurrentValuation']);



       //round approval and activation routes later will be moved to admin panel=======================
       Route::patch('/funding-rounds/{fundingRound}/approve', [FundingController::class, 'approveRound']);
       Route::patch('/funding-rounds/{fundingRound}/activate', [FundingController::class, 'activateRound']);
       Route::patch('/funding-rounds/{fundingRound}/close', [FundingController::class, 'closeRound']);
       Route::patch('/funding-rounds/{fundingRound}/reject', [FundingController::class, 'rejectRound']); 



       //invester funding simulation
       Route::post('/funding-investors', [FundingInvestorController::class, 'store']);





       //get form status 
       Route::get('/funding/form-status', [FundingController::class, 'getFormStatus']);
       Route::get('/funding/rounds-details/{roundId}', [FundingController::class, 'getRoundDetails']);











    //    Route::middleware(['role:admin'])->group(function () {
    //     Route::get('/admin/check', function () {
    //         return response()->json(['message' => 'Admin route is working!']);
    //     });
    //     Route::get('/admin/pending-rounds', [AdminFundingController::class, 'getPendingRounds']);
    // }); 



});













// });

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/admin/check', function () {
        return response()->json(['message' => 'Admin route is working!']);
    });
    Route::get('/admin/pending-rounds', [AdminFundingController::class, 'getPendingRounds']);

    Route::patch('/admin/pending-rounds/{fundingRound}/approve', [AdminFundingController::class, 'approveRound']);
    Route::patch('/admin/pending-rounds/{fundingRound}/activate', [AdminFundingController::class, 'activateRound']);
    Route::patch('/admin/pending-rounds/{fundingRound}/close', [AdminFundingController::class, 'closeRound']);
    Route::patch('/admin/pending-rounds/{fundingRound}/reject', [AdminFundingController::class, 'rejectRound']);
    
    //start-up registered page route.     
    Route::get('/admin/startups', [AdminFundingController::class, 'getAllStartups']);




});
<?php

namespace App\Http\Controllers;

use App\Models\FundingInvestor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\FundingDetail;
use Illuminate\Support\Facades\Log;
use App\Models\FundingRound;


class FundingInvestorController extends Controller
{

    public function store(Request $request)
    {
        Log::info('Store method called', ['request' => $request->all()]);
    
        $validator = Validator::make($request->all(), [
            'round_id' => 'required|exists:funding_rounds,id',
            'name' => 'required|string',
            'amount_invested' => 'required|numeric|min:0',
            'grace_period_days' => 'required|integer|min:1',
            'commitment_date' => 'required|date' // Added date validation
        ]);
    
        if ($validator->fails()) {
            Log::warning('Validation failed', ['errors' => $validator->errors()]);
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        try {
            DB::beginTransaction();
            Log::info('Transaction started');
    
            // Get funding round and its detail
            $fundingRound = FundingRound::findOrFail($request->round_id);
            $fundingDetail = $fundingRound->fundingDetails;
    
            if (!$fundingDetail) {
                throw new \Exception('No funding detail found for this round');
            }
    
            Log::info('Funding detail retrieved', ['fundingDetail' => $fundingDetail]);
    
            // Calculate equity percentage
            $equityDiluted = ($request->amount_invested / $fundingDetail->valuation_amount) * 100;
            Log::info('Equity percentage calculated', ['equityDiluted' => $equityDiluted]);
    
            // Calculate grace period end from the provided commitment date
            $gracePeriodEnd = \Carbon\Carbon::parse($request->commitment_date)->addDays($request->grace_period_days);
    
            $investor = FundingInvestor::create([
                'funding_detail_id' => $fundingDetail->id,
                'name' => $request->name,
                'amount_invested' => $request->amount_invested,
                'equity_percentage' => $equityDiluted,
                'commitment_date' => $request->commitment_date, // Using provided date
                'grace_period_days' => $request->grace_period_days,
                'grace_period_end' => $gracePeriodEnd,
                'status' => 'invested'
            ]);
            Log::info('Investor created', ['investor' => $investor]);
    
            // Calculate total committed amount
            $totalCommitted = FundingInvestor::where('funding_detail_id', $fundingDetail->id)
                ->sum('amount_invested');
            $totalEquityDiluted = FundingInvestor::where('funding_detail_id', $fundingDetail->id)
                ->sum('equity_percentage');
                
            if ($totalCommitted > $fundingRound->target_amount) {
                throw new \Exception('Committed amount exceeds the target amount.');
            }
    
            // Update both funding raised and equity diluted in funding detail
            $fundingDetail->update([
                'equity_diluted' => $totalEquityDiluted
            ]);
            $fundingRound->update([
                'funding_raised' => $totalCommitted
            ]);
    
            Log::info('Total amounts calculated', [
                'totalCommitted' => $totalCommitted,
                'totalEquityDiluted' => $totalEquityDiluted
            ]);
    
            DB::commit();
            Log::info('Transaction committed');
    
            return response()->json([
                'data' => $investor,
                'equity_diluted' => round($equityDiluted, 2) . '%',
                'total_committed_amount' => $totalCommitted
            ], 201);
    
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction rolled back', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
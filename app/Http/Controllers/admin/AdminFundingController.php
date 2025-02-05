<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FundingRound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFundingController extends Controller
{
   
public function getPendingRounds()
{
    try {
        $rounds = FundingRound::with(['fundingDetails', 'user'])
            ->whereIn('approval_status', [
                FundingRound::STATUS_PENDING,
                FundingRound::STATUS_APPROVED, 
                FundingRound::STATUS_REJECTED
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($round) {
                return [
                    'id' => $round->id,
                    'company_name' => $round->user->company_name,
                    'round_type' => $round->round_type,
                    'current_valuation' => $round->current_valuation,
                    'target_amount' => $round->target_amount,
                    'minimum_investment' => $round->minimum_investment,
                    'shares_diluted' => $round->shares_diluted,
                    'date_raised' => $round->created_at->format('Y-m-d'),
                    'status' => $this->normalizeStatus($round->approval_status),
                    'comments' => $round->additional_comments,
                    'rejection_message' => $round->admin_rejection_message
                ];
            });

        // Group rounds by their normalized status
        $groupedRounds = $rounds->groupBy('status')
            ->map->values()
            ->toArray();

        return response()->json([
            'success' => true, 
            'data' => $groupedRounds
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Error in getPendingRounds: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error retrieving rounds',
            'error' => $e->getMessage()
        ], 500);
    }
}

private function normalizeStatus($status)
{
    $statusMap = [
        FundingRound::STATUS_PENDING => 'pending',
        FundingRound::STATUS_APPROVED => 'approved',
        FundingRound::STATUS_REJECTED => 'rejected'
    ];
    
    return $statusMap[$status] ?? 'unknown';
}

    public function approveRound(FundingRound $fundingRound)
    {
        try {
            DB::beginTransaction();

            if ($fundingRound->approval_status === 'approved') {
                return response()->json([
                    'message' => 'Round is already approved'
                ], 400);
            }

            $fundingRound->update([
                'approval_status' => FundingRound::STATUS_APPROVED,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Funding round approved successfully',
                'data' => $fundingRound->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function activateRound(FundingRound $fundingRound)
    {
        try {
            DB::beginTransaction();

            if ($fundingRound->approval_status !== FundingRound::STATUS_APPROVED) {
                return response()->json([
                    'message' => 'Round must be approved before activation'
                ], 400);
            }

            if ($fundingRound->is_active) {
                return response()->json([
                    'message' => 'Round is already active'
                ], 400);
            }

            $fundingRound->update([
                'is_active' => true,
                'approval_status' => FundingRound::STATUS_ACTIVE,
                // 'round_opening_date' => now()
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Funding round activated successfully',
                'data' => $fundingRound->fresh()->load('fundingDetails.investors')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function closeRound(FundingRound $fundingRound)
    {
        try {
            DB::beginTransaction();

            if ($fundingRound->approval_status !== FundingRound::STATUS_ACTIVE) {
                return response()->json([
                    'message' => 'Round must be approved before closing'
                ], 400);
            }

            // Calculate total shares diluted
            $totalSharesDiluted = $fundingRound->fundingDetails->investors->sum('equity_percentage');

            $fundingRound->update([
                'approval_status' => FundingRound::STATUS_CLOSED,
                'is_active' => false,
                'shares_diluted' => $totalSharesDiluted
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Funding round closed successfully',
                'data' => $fundingRound->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function rejectRound(Request $request, $fundingRoundId)
    {
        $request->validate([
            'rejection_message' => 'required|string',
        ]);
        
        DB::beginTransaction();
        try {
            $fundingRound = FundingRound::findOrFail($fundingRoundId);
            $fundingRound->update([
                'approval_status'         => FundingRound::STATUS_REJECTED,
                'admin_rejection_message' => $request->rejection_message,
            ]);
    
            DB::commit();
            return response()->json([
                'message'           => 'Funding round rejected successfully.',
                'admin_rejection_message' => $request->rejection_message
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

}
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FundingRound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class AdminFundingController extends Controller
{

    public function getPendingRoundDetails($roundId)
    {
        try {
            $fundingRound = FundingRound::with([
                'user:id,company_name,email',
                'fundingDetails',
               
            ])->findOrFail($roundId);

            return response()->json([
                'success' => true,
                'data' => [
                    'round_info' => [
                        'id' => $fundingRound->id,
                        'company_name' => $fundingRound->user->company_name,
                        'company_email' => $fundingRound->user->email,
                        'round_type' => $fundingRound->round_type,
                        'current_valuation' => $fundingRound->current_valuation,
                        'target_amount' => $fundingRound->target_amount,
                        'minimum_investment' => $fundingRound->minimum_investment,
                        'shares_diluted' => $fundingRound->shares_diluted,
                        'round_opening_date' => $fundingRound->round_opening_date,
                        'round_closing_date' => $fundingRound->round_closing_date,
                        'round_duration' => $fundingRound->round_duration,
                        'grace_period' => $fundingRound->grace_period,
                        'expected_returns' => $fundingRound->expected_returns,
                        'preferred_exit_strategy' => $fundingRound->preferred_exit_strategy,
                        'expected_exit_time' => $fundingRound->expected_exit_time,
                        'additional_comments' => $fundingRound->additional_comments,
                        'approval_status' => $fundingRound->approval_status,
                      
                    ],
                    
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Funding round not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error in getPendingRoundDetails: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving round details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getPendingRounds()
    {
        try {
            $rounds = FundingRound::with(['fundingDetails', 'user'])
                ->whereIn('approval_status', [
                    FundingRound::STATUS_PENDING,
                    // FundingRound::STATUS_APPROVED,
                    // FundingRound::STATUS_REJECTED
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
                'approval_status' => FundingRound::STATUS_REJECTED,
                'admin_rejection_message' => $request->rejection_message,
            ]);

            DB::commit();
            return response()->json([
                'message' => 'Funding round rejected successfully.',
                'admin_rejection_message' => $request->rejection_message
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }




    public function getAllStartups()
    {
        \Log::info('getAllStartups: Function called');

        try {
            \Log::info('getAllStartups: Querying users');
            $startups = User::where('user_type', '!=', 'admin')
                ->with([
                    'fundingRounds' => function ($query) {
                        \Log::info('getAllStartups: Ordering funding rounds by sequence_number');
                        $query->orderBy('sequence_number');
                    },
                    'fundingRounds.fundingDetails',
                    'fundingRounds.fundingDetails.investors'
                ])
                ->get();

            \Log::info('getAllStartups: Users queried', ['count' => $startups->count()]);

            if ($startups->isEmpty()) {
                \Log::warning('getAllStartups: No startups found');
                return response()->json([
                    'success' => false,
                    'message' => 'No startups found'
                ], 404);
            }

            \Log::info('getAllStartups: Mapping startups data');
            $startups = $startups->map(function ($user) {
                \Log::info('getAllStartups: Mapping user', ['user_id' => $user->id]);
                return [
                    // 'startup' => [
                    'id' => $user->id,
                    'company_name' => $user->company_name,
                    // 'email' => $user->email,
                    'registration_date' => $user->created_at->format('Y-m-d'),
                    'sectors' => $user->sectors,
                    // ],
                    'funding_rounds' => $user->fundingRounds->pluck('round_type')->all()
                ];
            });

            \Log::info('getAllStartups: Successfully mapped startups data');

            return response()->json([
                'success' => true,
                'data' => $startups
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error in getAllStartups: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving startups data',
                'error' => $e->getMessage()
            ], 500);
        }
    }






}
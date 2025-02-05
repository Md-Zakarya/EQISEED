<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FundingRound;
use App\Models\FundingDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\PredefinedRound;


class FundingController extends Controller
{


    public function getFormStatus()
    {
        try {
            \Log::info('Starting getFormStatus check');

            $user = auth()->user();
            \Log::info('User retrieved', ['user_id' => $user->id]);

            // Get all user's rounds with their funding details and investors
            $rounds = FundingRound::where('user_id', $user->id)
                ->with(['fundingDetails.investors'])
                ->get();

            \Log::info('Retrieved rounds', ['count' => $rounds->count()]);

            // If no funding rounds exist, immediately return "not_filled"
            if ($rounds->isEmpty()) {
                \Log::info('No rounds created, marking form as not_filled');
                return response()->json([
                    'form_status' => 'filled',
                    'rounds' => []
                ]);
            }

            // Check if any round has funding or investors
            $hasFilledForm = $rounds->contains(function ($round) {
                $hasFunding = !empty($round->funding_raised) && $round->funding_raised > 0;
                $hasInvestors = $round->fundingDetails && $round->fundingDetails->investors->count() > 0;

                \Log::info('Round check', [
                    'round_type' => $round->round_type,
                    'has_funding' => $hasFunding,
                    'has_investors' => $hasInvestors
                ]);

                return $hasFunding || $hasInvestors;
            });

            // Additional check: if at least one round was raised from Equiseed (isRaisedFromEquiseed equals 1)
            $hasRaisedFromEquiseed = $rounds->contains(function ($round) {
                return isset($round->isRaisedFromEquiseed) && $round->isRaisedFromEquiseed == 1;
            });
            \Log::info('Equiseed check', ['has_raised_equiseed' => $hasRaisedFromEquiseed]);

            // Combine the conditions
            $hasFilledForm = $hasFilledForm || $hasRaisedFromEquiseed;

            $roundNames = $rounds->pluck('round_type')->toArray();
            \Log::info('Round names collected', ['names' => $roundNames]);

            \Log::info('Completing getFormStatus', [
                'form_status' => $hasFilledForm ? 'filled' : 'not_filled',
                'rounds_count' => count($roundNames)
            ]);

            return response()->json([
                'form_status' => $hasFilledForm ? 'filled' : 'not_filled',
                'rounds' => $roundNames
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getFormStatus', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    public function getUserRounds()
    {
        try {
            $rounds = FundingRound::where('user_id', auth()->id())
                ->with('fundingDetails')
                ->select('id', 'round_type', 'isRaisedFromEquiseed', 'approval_status')
                ->orderBy('sequence_number')
                ->get()
                ->filter(function ($round) {
                    return !$round->fundingDetails ||
                        $round->fundingDetails->has_not_raised_before == 0;
                })
                ->map(function ($round) {
                    return [
                        'id' => $round->id,
                        'name' => $round->round_type,
                        'isRaisedFromEquiseed' => $round->isRaisedFromEquiseed,
                        'approvalStatus' => $round->approval_status,
                    ];
                })->values();

            if ($rounds->isEmpty()) {
                $userRounds = auth()->user()->rounds;
                if (!empty($userRounds)) {
                    $rounds = collect($userRounds)->map(function ($round) {
                        return [
                            'id' => null,
                            'name' => strtoupper($round),
                            'fundingRaised' => null,
                            'isRaisedFromEquiseed' => null,
                            'approvalStatus' => null,
                        ];
                    })->values();
                }
            }

            if ($rounds->isNotEmpty() && isset($rounds->last()['approvalStatus'])) {
                $lastApprovalStatus = strtolower($rounds->last()['approvalStatus']);
                $canStartNewRound = in_array($lastApprovalStatus, ['na', 'closed']);
            } else {
                $canStartNewRound = true;
            }

            return response()->json([
                'success' => true,
                'data' => $rounds->toArray(),
                'canStartNewRound' => $canStartNewRound,
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error in getUserRounds: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving rounds',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function storeBulk(Request $request)
    {
        try {
            DB::beginTransaction();

            $updatedRounds = [];

            foreach ($request->rounds as $roundData) {
                // Find existing round by user_id and round_type
                $normalizedRoundType = strtolower(str_replace(' ', '_', $roundData['round_type']));

                // Find existing round by user_id and normalized round_type
                $fundingRound = FundingRound::where('user_id', auth()->id())
                    ->whereRaw('LOWER(REPLACE(round_type, " ", "_")) = ?', [$normalizedRoundType])
                    ->first();

                if (!$fundingRound) {
                    throw new \Exception("Funding round {$roundData['round_type']} not found");
                }
                // Update funding round
                $fundingRound->update([
                    'funding_raised' => $roundData['has_not_raised_before'] ? 0 : $roundData['funding_raised'],
                    'form_type' => 'legacy',
                    'is_active' => false
                ]);

                // Update or create funding detail
                $fundingDetail = $fundingRound->fundingDetails()->updateOrCreate(
                    ['funding_round_id' => $fundingRound->id],
                    [
                        'valuation_amount' => $roundData['has_not_raised_before'] ? 0 : $roundData['valuation_amount'],
                        'funding_date' => $roundData['has_not_raised_before'] ? now() : $roundData['funding_date'],
                        'has_not_raised_before' => $roundData['has_not_raised_before'],
                        'equity_diluted' => $roundData['has_not_raised_before'] ? 0 : $roundData['equity_diluted'],
                    ]
                );

                // Handle investors
                if (!$roundData['has_not_raised_before']) {
                    // Delete existing investors
                    $fundingDetail->investors()->delete();

                    // Create new investors
                    foreach ($roundData['investors'] as $investor) {
                        if (!isset($investor['amount_invested'])) {
                            throw new \Exception('Investor amount is required');
                        }
                        $fundingDetail->investors()->create([
                            'name' => $investor['name'],
                            'amount_invested' => $investor['amount_invested'] ?? 0,
                            'commitment_date' => null,
                            'grace_period_days' => null,
                            'grace_period_end' => null,
                            'equity_percentage' => null,
                            'status' => null,
                        ]);
                    }
                }

                // Handle documents
                // if (isset($roundData['documents']) && !$roundData['has_not_raised_before']) {
                //     // Delete existing documents
                //     $fundingDetail->documents()->delete();

                //     foreach ($roundData['documents'] as $document) {
                //         if (!isset($document['file'])) {
                //             continue;
                //         }

                //         // For testing purposes, use static paths and names
                //         $filePath = 'funding-documents/sample.pdf';
                //         $originalName = 'sample.pdf';

                //         $fundingDetail->documents()->create([
                //             'file_path' => $filePath,
                //             'original_name' => $originalName
                //         ]);
                //     }
                // }

                // $updatedRounds[] = $fundingRound->fresh()->load('fundingDetails.investors', 'fundingDetails.documents');
            }

            DB::commit();
            return response()->json([
                'message' => 'Funding rounds updated successfully',
                'data' => $updatedRounds
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function newRound(Request $request)
    {
        $validator = Validator::make($request->all(), [
            //    'round_type' => 'required|string',
            'round_type' => 'required|string|unique:funding_rounds,round_type,NULL,id,user_id,' . auth()->id(),
            'current_valuation' => 'required|numeric',
            'shares_diluted' => 'required|numeric|between:0,100',
            'target_amount' => 'required|numeric',
            'minimum_investment' => 'required|numeric',
            'round_opening_date' => 'required|date',
            'round_duration' => 'required|in:7,14,21',
            'grace_period' => 'required|in:3,5,7',
            'preferred_exit_strategy.*' => 'string',
            'expected_exit_time' => 'required|in:3-5,5-7,7-9',
            'expected_returns' => 'required|numeric',
            'additional_comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $sequence = FundingRound::where('user_id', auth()->id())->count() + 1;
            $closingDate = date('Y-m-d', strtotime($request->round_opening_date . ' + ' . $request->round_duration . ' days'));

            $fundingRound = FundingRound::create([
                'user_id' => auth()->id(),
                'form_type' => 'new',  // Mark as new form
                'round_type' => $request->round_type,
                'isRaisedFromEquiseed' => true,
                'current_valuation' => $request->current_valuation,
                'shares_diluted' => $request->shares_diluted,
                'target_amount' => $request->target_amount,
                'minimum_investment' => $request->minimum_investment,
                'round_opening_date' => $request->round_opening_date,
                'round_duration' => $request->round_duration,
                'grace_period' => $request->grace_period,
                'preferred_exit_strategy' => $request->preferred_exit_strategy,
                'expected_exit_time' => $request->expected_exit_time,
                'expected_returns' => $request->expected_returns,
                'additional_comments' => $request->additional_comments,
                'round_closing_date' => $closingDate,
                'sequence_number' => $sequence,
                'approval_status' => FundingRound::STATUS_PENDING
            ]);

            $fundingDetail = $fundingRound->fundingDetails()->create([
                'valuation_amount' => $request->current_valuation,
                'funding_date' => $request->round_opening_date,
                'has_not_raised_before' => false,
                'equity_diluted' => 0,

            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'New funding round created successfully',
                'data' => $fundingRound
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create funding round',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getLatestCurrentValuation()
    {
        try {
            $userId = auth()->id();
            Log::info('Fetching latest funding round for user: ' . $userId);


            $latestRound = FundingRound::with('fundingDetails')
                ->where('user_id', $userId)
                ->orderByDesc('sequence_number')
                ->first();

            if (!$latestRound) {
                Log::warning('No funding round found for user: ' . $userId);
                $firstRound = PredefinedRound::orderBy('sequence')->first();

                return response()->json([
                    'success' => true,
                    'current_valuation' => 0,
                    'current_round' => null,
                    'next_round' => $firstRound?->name
                ], 200);
            }

            $currentValuation = $latestRound->fundingDetails?->valuation_amount ?? null;
            $nextRound = $this->findNextRound($latestRound->round_type);

            Log::info('Current valuation for user ' . $userId . ': ' . $currentValuation, [
                'funding_round' => $latestRound,
                'funding_details' => $latestRound->fundingDetails,
                'next_round' => $nextRound?->name
            ]);

            return response()->json([
                'success' => true,
                'current_valuation' => $currentValuation,
                'current_round' => $latestRound->round_type,
                'next_round' => $nextRound?->name
            ], 200);
        } catch (\Exception $e) {
            $userId = auth()->id();
            Log::error('Error retrieving current valuation for user ' . $userId . ': ' . $e->getMessage(), [
                'user_id' => $userId,
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving current valuation',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function findNextRound(string $currentRoundType): ?PredefinedRound
{
    try {
        Log::info('Starting findNextRound function', ['current_round_type' => $currentRoundType]);

        // Normalize the current round type
        $normalizedCurrentRound = $this->normalizeRoundName($currentRoundType);
        Log::info('Normalized current round type', ['normalized_current_round' => $normalizedCurrentRound]);

        // Get sorted predefined rounds
        $predefinedRounds = PredefinedRound::orderBy('sequence')->get();
        Log::info('Retrieved predefined rounds', ['predefined_rounds_count' => $predefinedRounds->count()]);

        // Find current round
        $currentRound = $predefinedRounds->filter(function ($round) use ($normalizedCurrentRound) {
            $normalizedRoundName = $this->normalizeRoundName($round->name);
            Log::debug('Comparing predefined round', [
                'predefined_round_name' => $round->name,
                'normalized_predefined_round_name' => $normalizedRoundName,
                'normalized_current_round' => $normalizedCurrentRound
            ]);
            return $normalizedRoundName === $normalizedCurrentRound;
        })->first();

        Log::info('Current round search result', ['current_round_found' => $currentRound ? true : false]);

        // Get all rounds raised by the user
        $userRounds = FundingRound::where('user_id', auth()->id())
            ->orderBy('sequence_number')
            ->get();
        Log::info('Retrieved user rounds', ['user_rounds_count' => $userRounds->count(), 'user_rounds' => $userRounds->toArray()]);

        // Extract normalized names of raised rounds
        $raisedRounds = $userRounds->map(function ($round) {
            return $this->normalizeRoundName($round->round_type);
        })->toArray();
        Log::info('Raised rounds', ['raised_rounds' => $raisedRounds]);

        if (!$currentRound) {
            Log::info('Current round is not predefined, searching in user rounds');

            // If current round is not predefined, find the last predefined round 
            // with sequence less than current round's sequence
            $lastPredefinedRound = null;
            foreach ($userRounds as $round) {
                Log::info('Processing user round', [
                    'round_id' => $round->id,
                    'round_type' => $round->round_type
                ]);

                $normalizedRoundType = $this->normalizeRoundName($round->round_type);
                Log::debug('Normalized round type', [
                    'original' => $round->round_type,
                    'normalized' => $normalizedRoundType
                ]);

                $predefinedRound = $predefinedRounds->filter(function ($round) use ($normalizedRoundType) {
                    $normalizedPredefinedRoundName = $this->normalizeRoundName($round->name);
                    Log::debug('Comparing user round with predefined round', [
                        'user_round_normalized' => $normalizedRoundType,
                        'predefined_round_normalized' => $normalizedPredefinedRoundName
                    ]);
                    return $normalizedPredefinedRoundName === $normalizedRoundType;
                })->first();
                Log::debug('Predefined round lookup result', [
                    'found' => $predefinedRound ? true : false,
                    'predefined_name' => $predefinedRound ? $predefinedRound->name : null,
                    'sequence' => $predefinedRound ? $predefinedRound->sequence : null
                ]);

                if ($predefinedRound) {
                    $lastPredefinedRound = $predefinedRound;
                    Log::info('Updated last predefined round', [
                        'round_id' => $round->id,
                        'predefined_name' => $predefinedRound->name,
                        'sequence' => $predefinedRound->sequence
                    ]);
                }
            }

            if ($lastPredefinedRound) {
                Log::info('Last predefined round found in user sequence', [
                    'last_predefined_round_name' => $lastPredefinedRound->name,
                    'last_predefined_round_sequence' => $lastPredefinedRound->sequence
                ]);

                // Get next predefined round after the last known predefined round
                // Skip rounds that have already been raised
                $nextRound = $predefinedRounds
                    ->where('sequence', '>', $lastPredefinedRound->sequence)
                    ->filter(function ($round) use ($raisedRounds) {
                        return !in_array($this->normalizeRoundName($round->name), $raisedRounds);
                    })
                    ->first();

                Log::info('Next round after last predefined round', [
                    'next_round_found' => $nextRound ? true : false,
                    'next_round_name' => $nextRound ? $nextRound->name : 'none'
                ]);

                return $nextRound;
            }

            // If no predefined round found in sequence, return first predefined round
            Log::info('No predefined round found in user sequence, returning first predefined round');
            return $predefinedRounds->first();
        }

        // Get next round in regular predefined sequence
        // Skip rounds that have already been raised
        $nextRound = $predefinedRounds
            ->where('sequence', '>', $currentRound->sequence)
            ->filter(function ($round) use ($raisedRounds) {
                return !in_array($this->normalizeRoundName($round->name), $raisedRounds);
            })
            ->first();

        Log::info('Next round in predefined sequence', [
            'current_round_name' => $currentRound->name,
            'current_round_sequence' => $currentRound->sequence,
            'next_round_found' => $nextRound ? true : false,
            'next_round_name' => $nextRound ? $nextRound->name : 'none'
        ]);

        return $nextRound;

    } catch (\Exception $e) {
        Log::error('Error in findNextRound', [
            'current_round_type' => $currentRoundType,
            'error_message' => $e->getMessage(),
            'error_trace' => $e->getTraceAsString()
        ]);
        return null;
    }
}








//     private function findNextRound(string $currentRoundType): ?PredefinedRound
// {
//     try {
//         Log::info('Starting findNextRound function', ['current_round_type' => $currentRoundType]);

//         // Normalize the current round type
//         $normalizedCurrentRound = $this->normalizeRoundName($currentRoundType);
//         Log::info('Normalized current round type', ['normalized_current_round' => $normalizedCurrentRound]);

//         // Get sorted predefined rounds
//         $predefinedRounds = PredefinedRound::orderBy('sequence')->get();
//         Log::info('Retrieved predefined rounds', ['predefined_rounds_count' => $predefinedRounds->count()]);

//         // Find current round
//         $currentRound = $predefinedRounds->filter(function ($round) use ($normalizedCurrentRound) {
//             $normalizedRoundName = $this->normalizeRoundName($round->name);
//             Log::debug('Comparing predefined round', [
//                 'predefined_round_name' => $round->name,
//                 'normalized_predefined_round_name' => $normalizedRoundName,
//                 'normalized_current_round' => $normalizedCurrentRound
//             ]);
//             return $normalizedRoundName === $normalizedCurrentRound;
//         })->first();

//         // if (!$currentRound) {
//         //     Log::info('No current round found. Returning the first predefined round as next round.');
//         //     return $predefinedRounds->first();
//         // }
//         Log::info('Current round search result', ['current_round_found' => $currentRound ? true : false]);

//         if (!$currentRound) {
//             Log::info('Current round is not predefined, searching in user rounds');

//             // If current round is not predefined, find the last predefined round 
//             // with sequence less than current round's sequence
//             $userRounds = FundingRound::where('user_id', auth()->id())
//                 ->orderBy('sequence_number')
//                 ->get();
//             Log::info('Retrieved user rounds', ['user_rounds_count' => $userRounds->count(), 'user_rounds' => $userRounds->toArray()]);

//             // Find the last predefined round in user's sequence
//             $lastPredefinedRound = null;
//             foreach ($userRounds as $round) {
//                 Log::info('Processing user round', [
//                     'round_id' => $round->id,
//                     'round_type' => $round->round_type
//                 ]);

//                 $normalizedRoundType = $this->normalizeRoundName($round->round_type);
//                 Log::debug('Normalized round type', [
//                     'original' => $round->round_type,
//                     'normalized' => $normalizedRoundType
//                 ]);

//                 $predefinedRound = $predefinedRounds->filter(function ($round) use ($normalizedRoundType) {
//                     $normalizedPredefinedRoundName = $this->normalizeRoundName($round->name);
//                     Log::debug('Comparing user round with predefined round', [
//                         'user_round_normalized' => $normalizedRoundType,
//                         'predefined_round_normalized' => $normalizedPredefinedRoundName
//                     ]);
//                     return $normalizedPredefinedRoundName === $normalizedRoundType;
//                 })->first();
//                 Log::debug('Predefined round lookup result', [
//                     'found' => $predefinedRound ? true : false,
//                     'predefined_name' => $predefinedRound ? $predefinedRound->name : null,
//                     'sequence' => $predefinedRound ? $predefinedRound->sequence : null
//                 ]);

//                 if ($predefinedRound) {
//                     $lastPredefinedRound = $predefinedRound;
//                     Log::info('Updated last predefined round', [
//                         'round_id' => $round->id,
//                         'predefined_name' => $predefinedRound->name,
//                         'sequence' => $predefinedRound->sequence
//                     ]);
//                 }
//             }

//             if ($lastPredefinedRound) {
//                 Log::info('Last predefined round found in user sequence', [
//                     'last_predefined_round_name' => $lastPredefinedRound->name,
//                     'last_predefined_round_sequence' => $lastPredefinedRound->sequence
//                 ]);

//                 // Get next predefined round after the last known predefined round
//                 $nextRound = $predefinedRounds
//                     ->where('sequence', '>', $lastPredefinedRound->sequence)
//                     ->first();

//                 Log::info('Next round after last predefined round', [
//                     'next_round_found' => $nextRound ? true : false,
//                     'next_round_name' => $nextRound ? $nextRound->name : 'none'
//                 ]);

//                 return $nextRound;
//             }

//             // If no predefined round found in sequence, return first predefined round
//             Log::info('No predefined round found in user sequence, returning first predefined round');
//             return $predefinedRounds->first();
//         }

//         // Get next round in regular predefined sequence
//         $nextRound = $predefinedRounds
//             ->where('sequence', '>', $currentRound->sequence)
//             ->first();

//         Log::info('Next round in predefined sequence', [
//             'current_round_name' => $currentRound->name,
//             'current_round_sequence' => $currentRound->sequence,
//             'next_round_found' => $nextRound ? true : false,
//             'next_round_name' => $nextRound ? $nextRound->name : 'none'
//         ]);

//         return $nextRound;

//     } catch (\Exception $e) {
//         Log::error('Error in findNextRound', [
//             'current_round_type' => $currentRoundType,
//             'error_message' => $e->getMessage(),
//             'error_trace' => $e->getTraceAsString()
//         ]);
//         return null;
//     }
// }
    private function normalizeRoundName(string $roundName): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $roundName));
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

    public function rejectRound($fundingRoundId)
    {
        $fundingRound = FundingRound::findOrFail($fundingRoundId);
        $fundingRound->status = 'rejected';
        $fundingRound->save();

        return response()->json(['message' => 'Funding round rejected successfully.']);
    }

    public function getRoundDetails($roundId)
    {
        try {
            $fundingRound = FundingRound::where('id', $roundId)
                ->where('user_id', auth()->id())
                ->with(['fundingDetails.investors', 'fundingDetails.documents'])
                ->firstOrFail();

            // Prepare response based on status
            $responseData = [];

            // Case 1: Status is NA - Return minimal information
            if ($fundingRound->approval_status === FundingRound::STATUS_NA) {
                $responseData = [
                    'round_type' => $fundingRound->round_type,
                    'funding_raised' => $fundingRound->funding_raised,
                    'valuation' => $fundingRound->fundingDetails ? $fundingRound->fundingDetails->valuation_amount : null
                ];
            }
            // Case 2: Status is CLOSED
            else if ($fundingRound->approval_status === FundingRound::STATUS_CLOSED) {
                $responseData = [
                    'id' => $fundingRound->id,
                    'round_type' => $fundingRound->round_type,
                    'is_active' => $fundingRound->is_active,
                    'approval_status' => $fundingRound->approval_status,
                    'round_opening_date' => $fundingRound->round_opening_date,
                    'round_closing_date' => $fundingRound->round_closing_date,
                    'target_amount' => $fundingRound->target_amount,
                    // 'funding_raised' => $fundingRound->funding_raised,
                    'committed_amount' => $fundingRound->funding_raised,

                    'total_equity_diluted' => $fundingRound->fundingDetails ? $fundingRound->fundingDetails->equity_diluted : null,
                    'investors' => $fundingRound->fundingDetails ? $fundingRound->fundingDetails->investors->map(function ($investor) {
                        return [
                            'name' => $investor->name,
                            'commitment_date' => $investor->commitment_date,
                            'grace_period_end' => $investor->grace_period_end,
                            'amount_invested' => $investor->amount_invested,
                            'equity_diluted' => $investor->equity_percentage,
                        ];
                    }) : []
                ];
            }
            // Case 3: Round is Active
            else if ($fundingRound->is_active) {
                $responseData = [
                    'id' => $fundingRound->id,
                    'round_type' => $fundingRound->round_type,
                    'current_valuation' => $fundingRound->current_valuation,
                    'shares_diluted' => $fundingRound->shares_diluted,
                    'target_amount' => $fundingRound->target_amount,
                    'minimum_investment' => $fundingRound->minimum_investment,
                    'round_opening_date' => $fundingRound->round_opening_date,
                    'round_duration' => $fundingRound->round_duration,
                    'grace_period' => $fundingRound->grace_period,
                    'preferred_exit_strategy' => $fundingRound->preferred_exit_strategy,
                    'expected_exit_time' => $fundingRound->expected_exit_time,
                    'expected_returns' => $fundingRound->expected_returns,
                    'additional_comments' => $fundingRound->additional_comments,
                    'approval_status' => $fundingRound->approval_status,
                    'sequence_number' => $fundingRound->sequence_number,
                    'is_active' => $fundingRound->is_active,
                    'round_closing_date' => $fundingRound->round_closing_date,
                    'committed_amount' => $fundingRound->funding_raised,
                    'target_size' => $fundingRound->target_amount,
                    'total_equity_diluted' => $fundingRound->fundingDetails ? $fundingRound->fundingDetails->equity_diluted : null,
                    'investors' => $fundingRound->fundingDetails ? $fundingRound->fundingDetails->investors->map(function ($investor) {
                        return [
                            'name' => $investor->name,
                            'commitment_date' => $investor->commitment_date,
                            'grace_period_end' => $investor->grace_period_end,
                            'amount_invested' => $investor->amount_invested,
                            'equity_diluted' => $investor->equity_percentage,
                        ];
                    }) : []
                ];
            }
            // Case 4: Default case - Return basic round information
            else {
                $responseData = [
                    'id' => $fundingRound->id,
                    'round_type' => $fundingRound->round_type,
                    'current_valuation' => $fundingRound->current_valuation,
                    'shares_diluted' => $fundingRound->shares_diluted,
                    'target_amount' => $fundingRound->target_amount,
                    'minimum_investment' => $fundingRound->minimum_investment,
                    'round_opening_date' => $fundingRound->round_opening_date,
                    'round_duration' => $fundingRound->round_duration,
                    'grace_period' => $fundingRound->grace_period,
                    'preferred_exit_strategy' => $fundingRound->preferred_exit_strategy,
                    'expected_exit_time' => $fundingRound->expected_exit_time,
                    'expected_returns' => $fundingRound->expected_returns,
                    'additional_comments' => $fundingRound->additional_comments,
                    'approval_status' => $fundingRound->approval_status,
                    'sequence_number' => $fundingRound->sequence_number,
                    'is_active' => $fundingRound->is_active,
                    'round_closing_date' => $fundingRound->round_closing_date
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $responseData
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Funding round not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error in getRoundDetails: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving round details',
                'error' => $e->getMessage()
            ], 500);
        }
    }


}







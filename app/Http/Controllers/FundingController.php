<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FundingRound;
use App\Models\FundingDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;


class FundingController extends Controller
{

    public function getUserRounds()
    {
        try {
            $rounds = FundingRound::where('user_id', auth()->id())
                ->select('round_type', 'funding_raised', 'isRaisedFromEquiseed')
                ->orderBy('sequence_number')
                ->get()
                ->map(function ($round) {
                    return [
                        'name' => strtoupper($round->round_type),
                        'fundingRaised' => $round->funding_raised,
                        'isRaisedFromEquiseed' => $round->isRaisedFromEquiseed,

                    ];
                });

            if ($rounds->isEmpty()) {
                $userRounds = auth()->user()->rounds;
                if (!empty($userRounds)) {
                    $rounds = collect($userRounds)->map(function ($round) {
                        return [
                            'name' => strtoupper($round),
                            'fundingRaised' => null, // Assuming no fundingRaised data available in user rounds
                            'isRaisedFromEquiseed' => null // Assuming no isRaisedFromEquiseed data available in user rounds
                        ];
                    });
                }
            }

            return response()->json([
                'success' => true,
                'data' => $rounds
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

            $createdRounds = [];

            foreach ($request->rounds as $roundData) {
                $fundingRound = FundingRound::create([
                    'user_id' => auth()->id(),
                    'round_type' => $roundData['round_type'],
                    'funding_raised' => $roundData['has_not_raised_before'] ? 0 : $roundData['funding_raised'],
                    'sequence_number' => FundingRound::where('user_id', auth()->id())->count() + 1,
                    'form_type' => 'legacy',
                    'approval_status' => null,
                    'is_active' => false

                ]);

                $fundingDetail = $fundingRound->fundingDetails()->create([
                    'valuation_amount' => $roundData['has_not_raised_before'] ? 0 : $roundData['valuation_amount'],
                    'funding_date' => $roundData['has_not_raised_before'] ? now() : $roundData['funding_date'],
                    // 'details' => $roundData['has_not_raised_before'] ? '' : $roundData['details'],
                    'has_not_raised_before' => $roundData['has_not_raised_before'],
                    'equity_diluted' => $roundData['has_not_raised_before'] ? 0 : $roundData['equity_diluted'],
                ]);

                if (!$roundData['has_not_raised_before']) {
                    foreach ($roundData['investors'] as $investor) {
                        if (!isset($investor['amount_invested'])) {
                            throw new \Exception('Investor amount is required');
                        }
                        $fundingDetail->investors()->create(array_merge([

                            'commitment_date' => null,
                            'grace_period_days' => null,
                            'grace_period_end' => null,
                            'equity_percentage' => null,
                            'status' => null,
                        ], [
                            'name' => $investor['name'],
                            'amount_invested' => $investor['amount_invested'] ?? 0,
                        ]));
                    }
                }


                //path for the file saved (testing)
                if (isset($roundData['documents']) && !$roundData['has_not_raised_before']) {
                    foreach ($roundData['documents'] as $document) {
                        if (!isset($document['file'])) {
                            continue;
                        }

                        // For testing purposes, use static paths and names
                        $filePath = 'funding-documents/sample.pdf';
                        $originalName = 'sample.pdf';

                        $fundingDetail->documents()->create([
                            'file_path' => $filePath,
                            'original_name' => $originalName
                        ]);
                    }
                }


                //path for the file saved (production)
                // if (isset($roundData['documents']) && !$roundData['has_not_raised_before']) {
                //     foreach ($roundData['documents'] as $document) {
                //         if (!isset($document['file']) || !file_exists($document['file'])) {
                //             continue;
                //         }

                //         // $file = new \Illuminate\Http\UploadedFile(
                //         //     $document['file'],
                //         //     basename($document['file'])
                //         // );

                //         $file = new \Illuminate\Http\UploadedFile(
                //             storage_path('app/public/test-files/sample.pdf'), // Path to a static test file
                //             'sample.pdf'
                //         );

                //         $path = Storage::disk('public')->putFile('funding-documents', $file);
                //         $fundingDetail->documents()->create([
                //             'file_path' => $path,
                //             'original_name' => basename($document['file'])
                //         ]);
                //     }
                // }

                $createdRounds[] = $fundingRound->load('fundingDetails.investors', 'fundingDetails.documents');
            }

            DB::commit();
            return response()->json([
                'message' => 'Funding rounds created successfully',
                'data' => $createdRounds
            ], 201);

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


    public function getNewRounds()
{
    try {
        $newRounds = FundingRound::where('user_id', auth()->id())
            ->where('form_type', 'new')
            ->orderBy('sequence_number')
            ->get();

        if ($newRounds->isEmpty()) {
            $formattedRounds = collect([
                [
                    'id' => '',
                    'round_type' => '',
                    'current_valuation' => 10000000,
                    'shares_diluted' => 8,
                    'target_amount' => '',
                    'minimum_investment' => '',
                    'round_opening_date' => '',
                    'round_duration' => '',
                    'grace_period' => '',
                    'preferred_exit_strategy' => '',
                    'expected_exit_time' => '',
                    'expected_returns' => '',
                    'additional_comments' => '',
                    'approval_status' => '',
                    'sequence_number' => '',
                    'round_closing_date' => '',
                    'is_active' => ''
                ]
            ]);
        } else {
            $formattedRounds = $newRounds->map(function ($round) {
                if ($round->approval_status === FundingRound::STATUS_CLOSED) {
                    return [
                        'id' => $round->id,
                        'round_type' => $round->round_type,
                        'is_active' => $round->is_active,
                        'approval_status' => $round->approval_status,
                        'start_date' => $round->round_opening_date,
                        'closing_date' => $round->round_closing_date,
                        'target_amount' => $round->target_amount,
                        'committed_amount' => $round->funding_raised,
                        'total_equity_diluted' => $round->fundingDetails->equity_diluted,
                        'investors' => $round->fundingDetails->investors->map(function ($investor) {
                            return [
                                'name' => $investor->name,
                                'commitment_date' => $investor->commitment_date,
                                'grace_period_end' => $investor->grace_period_end,
                                'amount_invested' => $investor->amount_invested,
                                'equity_diluted' => $investor->equity_percentage,
                            ];
                        }),
                    ];
                }
                 else if ($round->is_active) {
                   
                    return [
                        'id' => $round->id,
                        'round_type' => $round->round_type,
                        'current_valuation' => $round->current_valuation,
                        'shares_diluted' => $round->shares_diluted,
                        'target_amount' => $round->target_amount,
                        'minimum_investment' => $round->minimum_investment,
                        'round_opening_date' => $round->round_opening_date,
                        'round_duration' => $round->round_duration,
                        'grace_period' => $round->grace_period,
                        'preferred_exit_strategy' => $round->preferred_exit_strategy,
                        'expected_exit_time' => $round->expected_exit_time,
                        'expected_returns' => $round->expected_returns,
                        'additional_comments' => $round->additional_comments,
                        'approval_status' => $round->approval_status,
                        'sequence_number' => $round->sequence_number,
                        'is_active' => $round->is_active,
                        'round_closing_date' => $round->round_closing_date,
                        'status' => $round->approval_status,
                        'start_date' => $round->round_opening_date,
                        'closing_date' => $round->round_closing_date,
                        'committed_amount' => $round->funding_raised,
                        'target_size' => $round->target_amount,
                        'total_equity_diluted' => $round->fundingDetails->equity_diluted,
                        'investors' => $round->fundingDetails->investors->map(function ($investor) {
                            return [
                                'name' => $investor->name,
                                'commitment_date' => $investor->commitment_date,
                                'grace_period_end' => $investor->grace_period_end,
                                'amount_invested' => $investor->amount_invested,
                                'equity_diluted' => $investor->equity_percentage,
                            ];
                        }),
                    ];
                } else {
                    return [
                        'id' => $round->id,
                        'round_type' => $round->round_type,
                        'current_valuation' => $round->current_valuation,
                        'shares_diluted' => $round->shares_diluted,
                        'target_amount' => $round->target_amount,
                        'minimum_investment' => $round->minimum_investment,
                        'round_opening_date' => $round->round_opening_date,
                        'round_duration' => $round->round_duration,
                        'grace_period' => $round->grace_period,
                        'preferred_exit_strategy' => $round->preferred_exit_strategy,
                        'expected_exit_time' => $round->expected_exit_time,
                        'expected_returns' => $round->expected_returns,
                        'additional_comments' => $round->additional_comments,
                        'approval_status' => $round->approval_status,
                        'sequence_number' => $round->sequence_number,
                        'is_active' => $round->is_active,
                        'round_closing_date' => $round->round_closing_date
                    ];
                }
            });
        }

        return response()->json([
            'success' => true,
            'data' => $formattedRounds
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error retrieving new rounds',
            'error' => $e->getMessage()
        ], 500);
    }
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

}



// public function getNewRounds()
// {
//     try {
//         $newRounds = FundingRound::where('user_id', auth()->id())
//             ->where('form_type', 'new')
//             ->orderBy('sequence_number')
//             ->get();

//         if ($newRounds->isEmpty()) {
//             $formattedRounds = collect([
//                 [
//                     'id' => '',
//                     'round_type' => '',
//                     'current_valuation' => 1000000,
//                     'shares_diluted' => 8,
//                     'target_amount' => '',
//                     'minimum_investment' => '',
//                     'round_opening_date' => '',
//                     'round_duration' => '',
//                     'grace_period' => '',
//                     'preferred_exit_strategy' => '',
//                     'expected_exit_time' => '',
//                     'expected_returns' => '',
//                     'additional_comments' => '',
//                     'approval_status' => '',
//                     'sequence_number' => '',
//                     'round_closing_date' => '',
//                     'is_active' => ''
//                 ]
//             ]);
//         } else {
//             $formattedRounds = $newRounds->map(function ($round) {
//                 return [
//                     'id' => $round->id,
//                     'round_type' => $round->round_type,
//                     'current_valuation' => $round->current_valuation,
//                     'shares_diluted' => $round->shares_diluted,
//                     'target_amount' => $round->target_amount,
//                     'minimum_investment' => $round->minimum_investment,
//                     'round_opening_date' => $round->round_opening_date,
//                     'round_duration' => $round->round_duration,
//                     'grace_period' => $round->grace_period,
//                     'preferred_exit_strategy' => $round->preferred_exit_strategy,
//                     'expected_exit_time' => $round->expected_exit_time,
//                     'expected_returns' => $round->expected_returns,
//                     'additional_comments' => $round->additional_comments,
//                     'approval_status' => $round->approval_status,
//                     'sequence_number' => $round->sequence_number,
//                     'is_active' => $round->is_active,
//                    'round_closing_date' => $round->round_closing_date
//                 ];
//             });
//         }

//         return response()->json([
//             'success' => true,
//             'data' => $formattedRounds
//         ], 200);
//     } catch (\Exception $e) {
//         return response()->json([
//             'success' => false,
//             'message' => 'Error retrieving new rounds',
//             'error' => $e->getMessage()
//         ], 500);
//     }
// } 





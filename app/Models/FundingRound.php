<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundingRound extends Model
{   
    const STATUS_PENDING = 'Currently in Review';
    const STATUS_APPROVED = 'Reviewed & Approved';
    const STATUS_REJECTED = 'Rejected';

    const STATUS_CLOSED = 'Closed'; 
    const STATUS_ACTIVE = 'Active';
    const STATUS_NA = 'NA';
        

    protected $fillable = [
        'user_id',
        'round_type',
        'is_active',
        'isRaisedFromEquiseed',
        'form_type',
        'current_valuation',
        'shares_diluted',
        'target_amount',
        'minimum_investment',
        'round_opening_date',
        'round_closing_date',
        'round_duration',
        'grace_period',
        'preferred_exit_strategy',
        'expected_exit_time',
        'expected_returns',
        'additional_comments',
        'funding_raised',
        'sequence_number',
        'approval_status',
        'admin_rejection_message',
    ];
    protected $casts = [
        'preferred_exit_strategy' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fundingDetails()
    {
        return $this->hasOne(FundingDetail::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundingDetail extends Model
{
    protected $fillable = [
        'funding_round_id',
        'valuation_amount',
        'funding_date',
        // 'details',
        'has_not_raised_before',
        'equity_diluted',
    ];

    public function fundingRound()
    {
        return $this->belongsTo(FundingRound::class);
    }

    public function investors()
    {
        return $this->hasMany(FundingInvestor::class);
    }

    public function documents()
    {
        return $this->hasMany(FundingDocument::class);
    }
}

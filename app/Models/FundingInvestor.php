<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundingInvestor extends Model
{
    protected $fillable = [
        'funding_detail_id',
        'name',
       
     
        'amount_invested',
        'commitment_date',
        'grace_period_days',
        'grace_period_end',
        'equity_percentage',
        'status'
        // Add other relevant fields here
    ];
    protected $dates = [
        'commitment_date',
        'grace_period_end'
    ];

    public function fundingDetail()
    {
        return $this->belongsTo(FundingDetail::class);
    }
}
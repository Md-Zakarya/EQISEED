<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundingInvestor extends Model
{
    protected $fillable = [
        'funding_detail_id',
        'name',
        'amount',
        // Add other relevant fields here
    ];

    public function fundingDetail()
    {
        return $this->belongsTo(FundingDetail::class);
    }
}
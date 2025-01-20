<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundingDocument extends Model
{
    protected $fillable = [
        'funding_detail_id',
        'file_path',
        'original_name',
        // Add other relevant fields here
    ];

    public function fundingDetail()
    {
        return $this->belongsTo(FundingDetail::class);
    }
}
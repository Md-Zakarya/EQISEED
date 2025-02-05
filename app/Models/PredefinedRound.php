<?php
// app/Models/PredefinedRound.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PredefinedRound extends Model
{
    protected $fillable = ['name', 'sequence'];
}
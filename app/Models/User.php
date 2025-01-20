<?php
// app/Models/User.php
namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Notifiable, HasApiTokens;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'has_experience',
        'sectors',
        'rounds',
        'linkedin_url',
        'phone',
        'country_code',
        'company_name',
        'company_role',
        'user_type',
    ];

    protected $casts = [
        'sectors' => 'array',
        'rounds' => 'array',
        'has_experience' => 'boolean',
    ];


    public function rounds()
{
    return $this->hasMany(FundingRound::class, 'user_id');
}
}
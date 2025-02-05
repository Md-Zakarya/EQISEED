<?php
// database/seeders/PredefinedRoundSeeder.php

namespace Database\Seeders;

use App\Models\PredefinedRound;
use Illuminate\Database\Seeder;

class PredefinedRoundSeeder extends Seeder
{
    public function run()
    {
        $rounds = [
            ['name' => 'Pre-Seed', 'sequence' => 1],
            ['name' => 'Seed', 'sequence' => 2],
            ['name' => 'Post-Seed', 'sequence' => 3],
            ['name' => 'Bridging', 'sequence' => 4],
            ['name' => 'Family & Friend', 'sequence' => 5],
            ['name' => 'Pre-Series A', 'sequence' => 6],
            ['name' => 'Series A', 'sequence' => 7],
            ['name' => 'Post-Series A', 'sequence' => 8],
            ['name' => 'Pre-Series B', 'sequence' => 9],
            ['name' => 'Open Market', 'sequence' => 10],
        ];

        foreach ($rounds as $round) {
            PredefinedRound::create($round);
        }
    }
}
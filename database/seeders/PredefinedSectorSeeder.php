<?php
// database/seeders/PredefinedSectorSeeder.php

namespace Database\Seeders;

use App\Models\PredefinedSector;
use Illuminate\Database\Seeder;

class PredefinedSectorSeeder extends Seeder
{
    public function run()
    {
        $sectors = [
            'Defense',
            'Agriculture & Cooperation',
            'Energy & Power',
            'Commerce & Industry',
            'Animal Husbandry & Fishing',
            'Art & Culture', 
            'Information & Broadcasting',
            'Transport & Infrastructure',
            'Youth Affairs & Sports',
            'Health & Family Welfare',
            'Home Affairs & National Security',
            'Communications & Information Technology'
        ];

        foreach ($sectors as $sector) {
            PredefinedSector::create(['name' => $sector]);
        }
    }
}
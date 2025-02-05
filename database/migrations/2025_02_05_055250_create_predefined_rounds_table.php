<?php
// database/migrations/xxxx_xx_xx_create_predefined_rounds_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('predefined_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('sequence')->unique();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('predefined_rounds');
    }
};
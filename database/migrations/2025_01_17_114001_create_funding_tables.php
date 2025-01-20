<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// filepath: /C:/Users/zakki/OneDrive/Desktop/EQISEEB WEB/backend/database/migrations/xxxx_xx_xx_create_funding_tables.php

return new class extends Migration
{
    public function up()
    {
        Schema::create('funding_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('round_type')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('form_type')->default('legacy');
            $table->decimal('funding_raised', 15, 2)->default(0);
            $table->boolean('isRaisedFromEquiseed')->default(false);
            $table->decimal('current_valuation', 15, 2)->nullable();
            $table->decimal('shares_diluted', 5, 2)->nullable();
            $table->decimal('target_amount', 15, 2)->nullable();
            $table->decimal('minimum_investment', 15, 2)->nullable();
            $table->date('round_opening_date')->nullable();
            $table->integer('round_duration')->nullable();
            $table->integer('grace_period')->nullable();
            $table->string('preferred_exit_strategy')->nullable();
            $table->string('expected_exit_time')->nullable();
            $table->decimal('expected_returns', 5, 2)->nullable();
            $table->text('additional_comments')->nullable();
            $table->integer('sequence_number');
            $table->string('approval_status')->nullable();
            $table->timestamps();
        });

        Schema::create('funding_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funding_round_id')->constrained()->onDelete('cascade');
            $table->decimal('valuation_amount', 15, 2);
            $table->date('funding_date');
            // $table->text('details')->nullable();
            $table->boolean('has_not_raised_before')->default(false);
            $table->decimal('equity_diluted', 5, 2);
            $table->timestamps();
        });

        Schema::create('funding_investors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funding_detail_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::create('funding_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funding_detail_id')->constrained()->onDelete('cascade');
            $table->string('file_path');
            $table->string('original_name');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('funding_documents');
        Schema::dropIfExists('funding_investors');
        Schema::dropIfExists('funding_details');
        Schema::dropIfExists('funding_rounds');
    }
};
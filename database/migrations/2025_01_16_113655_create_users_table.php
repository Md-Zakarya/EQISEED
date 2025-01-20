<?php
// database/migrations/2023_10_10_000000_create_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->boolean('has_experience')->default(false);
            $table->json('sectors')->nullable();
            $table->json('rounds')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('phone');
            $table->string('country_code')->default('+91');
            $table->string('company_name')->nullable();
            $table->string('company_role')->nullable();
            $table->string('user_type'); 
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('users');
    }
}
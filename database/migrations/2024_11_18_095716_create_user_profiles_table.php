<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('avatar_url')->nullable();
            $table->text('bio')->nullable();
            $table->bigInteger('total_coins_earned')->default(0);
            $table->bigInteger('total_coins_spent')->default(0);
            $table->bigInteger('current_coins')->default(0);
            $table->bigInteger('total_videos_watched')->default(0);
            $table->bigInteger('total_watch_time')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profilies');
    }
};

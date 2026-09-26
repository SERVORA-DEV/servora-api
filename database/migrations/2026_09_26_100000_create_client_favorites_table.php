<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spas a mobile client has saved. Keyed on the login account (users) rather
// than the per-business clients row: a client can favorite a spa they've
// never booked with, before any clients row exists for that business.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->constrained('spa_branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'spa_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_favorites');
    }
};

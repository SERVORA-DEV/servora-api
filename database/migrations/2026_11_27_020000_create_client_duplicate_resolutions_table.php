<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_duplicate_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('first_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('second_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('kept_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at');
            $table->unique(['first_client_id', 'second_client_id'], 'client_duplicate_pair_unique');
            $table->index('second_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_duplicate_resolutions');
    }
};

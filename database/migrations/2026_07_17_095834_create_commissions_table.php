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
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('therapist_assignment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('commission_percentage', 5, 2);

            $table->decimal('commission_amount', 10, 2);

            $table->enum('status', [
                'Pending',
                'Paid',
                'Cancelled',
            ])->default('Pending');

            $table->timestamp('paid_at')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('therapist_assignment_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
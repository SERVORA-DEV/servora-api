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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_branch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('client_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('appointment_number', 50)
                ->unique();

            $table->date('appointment_date');
            $table->time('appointment_time');

            $table->enum('appointment_type', [
                'Reservation',
                'Walk-in',
            ]);

            $table->enum('source', [
                'Mobile',
                'Front Desk',
            ]);

            $table->enum('status', [
                'Pending',
                'Confirmed',
                'Checked In',
                'Completed',
                'Cancelled',
                'No Show',
            ])->default('Pending');

            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('cancellation_reason')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['spa_branch_id', 'appointment_date']);
            $table->index(['client_id']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The notifications table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('title', 150);
            $table->text('message');
            $table->enum('type', ['System', 'Appointment', 'Payment', 'Reward', 'Subscription', 'Verification', 'Registration', 'Attendance']);
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->uuid('reference_uuid')->nullable();
            $table->bigInteger('announcement_id')->nullable();
            $table->string('priority', 10)->nullable();
            $table->index('announcement_id', 'notifications_announcement_id_index');
            $table->index('is_read', 'notifications_is_read_index');
            $table->index('type', 'notifications_type_index');
            $table->index('user_id', 'notifications_user_id_index');
            $table->foreign('announcement_id', 'notifications_announcement_id_foreign')->references('id')->on('announcements')->onDelete('set null');
            $table->foreign('user_id', 'notifications_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

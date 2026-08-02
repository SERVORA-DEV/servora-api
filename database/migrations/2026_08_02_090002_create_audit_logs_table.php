<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable + set-null-on-delete: a removed account shouldn't
            // wipe out the historical entries it produced.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('table_name', 100);
            $table->unsignedBigInteger('record_id')->nullable();

            $table->enum('action', [
                'Create',
                'Update',
                'Delete',

                'Login',
                'Logout',

                'Register',
                'Verify Email',
                'Reset Password',
                'Change Password',

                'Approve',
                'Reject',
                'Suspend',
                'Restore',

                'Assign',
                'Unassign',

                'Check In',
                'Check Out',

                'Complete',
                'Cancel',
                'Refund',

                'Redeem',

                'Export',

                'Activate',
                'Deactivate',
            ]);

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('table_name');
            $table->index('record_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

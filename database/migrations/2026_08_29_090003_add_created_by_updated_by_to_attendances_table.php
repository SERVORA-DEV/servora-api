<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Cheap "created by X / last updated by Y" without joining audit_logs
    // every time the details view is opened — the full change-by-change
    // trail still lives in audit_logs (see AttendanceService corrections).
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('remarks')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};

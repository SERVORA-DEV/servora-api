<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->json('pending_renewal_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', fn (Blueprint $table) => $table->dropColumn('pending_renewal_email'));
    }
};

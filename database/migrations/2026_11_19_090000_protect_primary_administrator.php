<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_primary_admin')->default(false);
        });

        // Persist identity once: never infer protection from a mutable email,
        // the displayed name, or the current ordering of the administrator list.
        $id = DB::table('users')->where('role', 'system_administrator')->orderBy('id')->value('id');
        if ($id !== null) {
            DB::table('users')->where('id', $id)->update(['is_primary_admin' => true]);
        }

        // PostgreSQL (production) and SQLite (isolated tests) both support this.
        DB::statement('CREATE UNIQUE INDEX users_one_primary_admin ON users (is_primary_admin) WHERE is_primary_admin = true');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_one_primary_admin');
            $table->dropColumn('is_primary_admin');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The subscription_plans table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_plans')) {
            return;
        }

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('category');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 10, 2)->nullable();
            $table->decimal('yearly_price', 10, 2)->nullable();
            $table->enum('billing_cycle', ['Monthly', 'Yearly', 'Both']);
            $table->integer('max_branches');
            $table->integer('max_user_accounts');
            $table->boolean('package_access')->default(false);
            $table->boolean('reward_access')->default(false);
            $table->boolean('review_access')->default(false);
            $table->boolean('report_access')->default(false);
            $table->boolean('report_export')->default(false);
            $table->boolean('mobile_app_access')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->integer('app_booking_limit')->nullable();
            $table->string('export_mode', 10)->nullable();
            $table->integer('export_days')->nullable();
            $table->decimal('extra_branch_price', 12, 2)->nullable();
            $table->decimal('extra_account_price', 12, 2)->nullable();
            $table->smallInteger('trial_days')->nullable();
            $table->unique('uuid', 'subscription_plans_uuid_unique');
        });

        // Postgres only (a SQLite test database skips these).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_category_check CHECK (((category)::text = ANY (ARRAY[\'Basic\'::text, \'Premium\'::text, \'Enterprise\'::text, \'Trial\'::text])))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};

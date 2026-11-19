<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The client_vouchers table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_vouchers')) {
            return;
        }

        Schema::create('client_vouchers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('client_id');
            $table->bigInteger('customer_program_id');
            $table->string('source', 20);
            $table->bigInteger('source_billing_id')->nullable();
            $table->bigInteger('client_membership_id')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 12)->default('available');
            $table->timestamp('used_at')->nullable();
            $table->bigInteger('used_billing_id')->nullable();
            $table->bigInteger('used_by')->nullable();
            $table->timestamps();
            $table->string('program_name')->nullable();
            $table->json('deal')->nullable();
            $table->unique('uuid', 'client_vouchers_uuid_unique');
            $table->index(['client_id', 'status'], 'client_vouchers_client_id_status_index');
            $table->index(['customer_program_id', 'source'], 'client_vouchers_customer_program_id_source_index');
            $table->foreign('client_id', 'client_vouchers_client_id_foreign')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('client_membership_id', 'client_vouchers_client_membership_id_foreign')->references('id')->on('client_memberships')->onDelete('cascade');
            $table->foreign('customer_program_id', 'client_vouchers_customer_program_id_foreign')->references('id')->on('customer_programs')->onDelete('cascade');
            $table->foreign('source_billing_id', 'client_vouchers_source_billing_id_foreign')->references('id')->on('billings')->onDelete('set null');
            $table->foreign('used_billing_id', 'client_vouchers_used_billing_id_foreign')->references('id')->on('billings')->onDelete('set null');
            $table->foreign('used_by', 'client_vouchers_used_by_foreign')->references('id')->on('users')->onDelete('set null');
        });

        // Tables made before this one that point here.
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('client_voucher_id', 'appointments_client_voucher_id_foreign')->references('id')->on('client_vouchers')->onDelete('set null');
        });
        Schema::table('billings', function (Blueprint $table) {
            $table->foreign('client_voucher_id', 'billings_client_voucher_id_foreign')->references('id')->on('client_vouchers')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign('appointments_client_voucher_id_foreign');
        });
        Schema::table('billings', function (Blueprint $table) {
            $table->dropForeign('billings_client_voucher_id_foreign');
        });
        Schema::dropIfExists('client_vouchers');
    }
};

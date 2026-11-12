<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A GCash account is added to the owner's saved payment methods without a
    // Xendit payment token: Xendit cannot save a GCash account for this
    // business to charge again ("No available channels found" for a SAVE
    // session), so GCash is approved each time it is used (BusinessPaymentMethod::
    // paysEachTime). Such a row has no token; the column keeps its unique index
    // (a unique index allows any number of NULLs), cards and Maya still carry theirs.
    public function up(): void
    {
        Schema::table('business_payment_methods', function (Blueprint $table) {
            $table->string('xendit_payment_token_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // A method with no token cannot exist once the column is required again.
        DB::table('business_payment_methods')->whereNull('xendit_payment_token_id')->delete();

        Schema::table('business_payment_methods', function (Blueprint $table) {
            $table->string('xendit_payment_token_id', 64)->nullable(false)->change();
        });
    }
};

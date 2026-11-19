<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The customer_program_items table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_program_items')) {
            return;
        }

        Schema::create('customer_program_items', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('membership_program_id');
            $table->bigInteger('item_program_id');
            $table->timestamps();
            $table->unique(['membership_program_id', 'item_program_id'], 'customer_program_items_membership_program_id_item_program_id_un');
            $table->foreign('item_program_id', 'customer_program_items_item_program_id_foreign')->references('id')->on('customer_programs')->onDelete('cascade');
            $table->foreign('membership_program_id', 'customer_program_items_membership_program_id_foreign')->references('id')->on('customer_programs')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_program_items');
    }
};

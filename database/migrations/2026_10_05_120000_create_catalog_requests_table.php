<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A manager asking the owner for a service or package the catalog
        // doesn't have. The catalog is the owner's, so nothing is created
        // here: the owner adds it (or declines) and the manager is told
        // (see CatalogRequestService).
        Schema::create('catalog_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('spa_business_id')->constrained('spa_businesses')->cascadeOnDelete();
            $table->foreignId('spa_branch_id')->nullable()->constrained('spa_branches')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // service | package
            $table->string('kind', 20)->default('service');
            $table->string('name', 120);
            $table->string('category', 80)->nullable();
            // The manager's own words: lengths, a price idea, why clients ask.
            $table->string('details', 1000)->nullable();

            // pending | added | declined | withdrawn
            $table->string('status', 20)->default('pending');

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->index(['spa_business_id', 'status']);
            $table->index(['spa_branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_requests');
    }
};

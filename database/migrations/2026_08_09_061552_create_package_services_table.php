<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Which services make up a package, how many of each, and their display
    // order — a pure pivot, cascades both ways since a row here is
    // meaningless once either side is gone.
    public function up(): void
    {
        Schema::create('package_services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(1);

            $table->timestamps();

            $table->unique(['package_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_services');
    }
};

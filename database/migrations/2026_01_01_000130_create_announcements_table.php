<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The announcements table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcements')) {
            return;
        }

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->bigInteger('sender_id')->nullable();
            $table->string('audience', 20);
            $table->string('scope', 10);
            $table->string('priority', 10)->default('normal');
            $table->string('title', 150);
            $table->text('message');
            $table->json('targets')->nullable();
            $table->string('request_id', 64);
            $table->string('status', 10)->default('sent');
            $table->integer('recipient_count')->default(0);
            $table->timestamps();
            $table->unique('request_id', 'announcements_request_id_unique');
            $table->unique('uuid', 'announcements_uuid_unique');
            $table->index('audience', 'announcements_audience_index');
            $table->index('created_at', 'announcements_created_at_index');
            $table->foreign('sender_id', 'announcements_sender_id_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};

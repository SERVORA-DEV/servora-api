<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The push_subscriptions table as it stands (one file per table). On a database that already
// has it, this does nothing; a new database gets it whole.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->string('kind', 10);
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->string('p256dh', 200)->nullable();
            $table->string('auth', 100)->nullable();
            $table->boolean('quiet')->default(false);
            $table->string('user_agent', 300)->nullable();
            $table->timestamps();
            $table->unique('endpoint_hash', 'push_subscriptions_endpoint_hash_unique');
            $table->index('user_id', 'push_subscriptions_user_id_index');
            $table->foreign('user_id', 'push_subscriptions_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

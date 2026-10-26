<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where to send a pop-up for a new notification while Servora isn't open
// (App\Service\PushSender): a browser that turned pop-ups on (Web Push), or a
// phone with the client app (Firebase Cloud Messaging).
//
//   kind       web | fcm
//   endpoint   the browser's push URL, or the phone's FCM token
//   p256dh, auth   the browser's keys for encrypting what is sent (web only)
//   quiet      the device has Notification sound off: pop up without a sound
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->text('endpoint');
            // sha256 of the endpoint: one row per browser or phone, whoever
            // signed in on it last (an endpoint is too long to index as is).
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 200)->nullable();
            $table->string('auth', 100)->nullable();
            $table->boolean('quiet')->default(false);
            $table->string('user_agent', 300)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

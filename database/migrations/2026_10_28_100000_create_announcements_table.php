<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// System Administrator announcements. What was sent lives in `announcements`
// and is never edited afterwards; what each person received is an ordinary row
// in `notifications` pointing back here (announcement_id), so delivery and read
// status come straight from the existing notification feed, and automated
// notifications (announcement_id null) stay apart from broadcasts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Kept if the sender's account is later removed.
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();

            // admins | businesses | branches | clients
            $table->string('audience', 20);
            // all | selected
            $table->string('scope', 10);
            // normal | important | urgent
            $table->string('priority', 10)->default('normal');

            $table->string('title', 150);
            $table->text('message');

            // The uuids picked when scope is "selected"; null for "all".
            $table->json('targets')->nullable();

            // Sent by the form with every submit: a repeated click or a retry
            // carries the same id and is answered with the first announcement.
            $table->string('request_id', 64)->unique();

            // sent. (An announcement that could not be delivered is not saved:
            // delivery is all-or-nothing, so no one is half-notified.)
            $table->string('status', 10)->default('sent');
            $table->unsignedInteger('recipient_count')->default(0);

            $table->timestamps();

            $table->index('created_at');
            $table->index('audience');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('announcement_id')->nullable()->after('reference_uuid')
                ->constrained('announcements')->nullOnDelete();
            // normal | important | urgent: set on announcements only.
            $table->string('priority', 10)->nullable()->after('type');
            $table->index('announcement_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('announcement_id');
            $table->dropColumn('priority');
        });
        Schema::dropIfExists('announcements');
    }
};

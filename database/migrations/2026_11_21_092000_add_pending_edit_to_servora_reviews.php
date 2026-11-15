<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An owner's edit of a review that is already on the Reviews page waits here
// for a check, while the published words stay up. edited_at marks a review
// whose edit was published ("Edited" on the page). Nullable columns only:
// every existing review reads as before.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servora_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('pending_rating')->nullable()->after('show_business');
            $table->string('pending_title', 80)->nullable()->after('pending_rating');
            $table->text('pending_body')->nullable()->after('pending_title');
            $table->boolean('pending_show_business')->nullable()->after('pending_body');
            $table->timestamp('pending_at')->nullable()->after('pending_show_business');
            $table->timestamp('edited_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('servora_reviews', function (Blueprint $table) {
            $table->dropColumn(['pending_rating', 'pending_title', 'pending_body', 'pending_show_business', 'pending_at', 'edited_at']);
        });
    }
};

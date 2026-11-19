<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The approval_requests table as it stands (one file per table): what a manager asks the owner to
// approve, one row per request — `subject` says which kind: 'catalog' (a service or package the
// catalog doesn't have — App\Models\CatalogRequest) or 'program' (a customer program for their
// branch — App\Models\ProgramRequest). Each model sees only its own rows.
//
// It replaces catalog_requests and program_requests: their rows are copied over (catalog requests
// keep their ids; program requests get new ones after them and keep their uuids, which is how
// notifications point at them). Those two tables are left as they are until a later migration
// drops them.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approval_requests')) {
            Schema::create('approval_requests', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid');
                $table->string('subject', 20);
                $table->bigInteger('spa_business_id');
                $table->bigInteger('spa_branch_id')->nullable();
                $table->bigInteger('requested_by_user_id')->nullable();
                // catalog: service, package…; program: voucher, discount, membership
                $table->string('kind', 20);
                $table->string('name', 120);
                $table->string('category', 80)->nullable();
                // The requester's note (a catalog request's "details").
                $table->string('note', 1000)->nullable();
                $table->json('payload')->nullable();
                $table->bigInteger('customer_program_id')->nullable();
                $table->string('status', 20)->default('pending');
                $table->bigInteger('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_note', 500)->nullable();
                $table->timestamps();
                $table->unique('uuid', 'approval_requests_uuid_unique');
                $table->index(['subject', 'spa_business_id', 'status'], 'approval_requests_subject_business_status_index');
                $table->index(['subject', 'spa_branch_id', 'status'], 'approval_requests_subject_branch_status_index');
                $table->foreign('spa_business_id', 'approval_requests_spa_business_id_foreign')->references('id')->on('spa_businesses')->onDelete('cascade');
                $table->foreign('spa_branch_id', 'approval_requests_spa_branch_id_foreign')->references('id')->on('spa_branches')->onDelete('set null');
                $table->foreign('requested_by_user_id', 'approval_requests_requested_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
                $table->foreign('decided_by_user_id', 'approval_requests_decided_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
                $table->foreign('customer_program_id', 'approval_requests_customer_program_id_foreign')->references('id')->on('customer_programs')->onDelete('set null');
            });
        }

        // A row already copied (this run twice) is left as it is.
        if (Schema::hasTable('catalog_requests')) {
            $payload = Schema::hasColumn('catalog_requests', 'payload') ? 'payload' : 'null';
            DB::statement("insert into approval_requests
                (id, uuid, subject, spa_business_id, spa_branch_id, requested_by_user_id, kind, name, category, note, payload,
                 customer_program_id, status, decided_by_user_id, decided_at, decision_note, created_at, updated_at)
                select id, uuid, 'catalog', spa_business_id, spa_branch_id, requested_by_user_id, kind, name, category, details, {$payload},
                 null, status, decided_by_user_id, decided_at, decision_note, created_at, updated_at
                from catalog_requests order by id
                on conflict do nothing");
        }
        $this->counterPastHighest();

        if (Schema::hasTable('program_requests')) {
            DB::statement("insert into approval_requests
                (uuid, subject, spa_business_id, spa_branch_id, requested_by_user_id, kind, name, category, note, payload,
                 customer_program_id, status, decided_by_user_id, decided_at, decision_note, created_at, updated_at)
                select uuid, 'program', spa_business_id, spa_branch_id, requested_by_user_id, type, name, null, note, payload,
                 customer_program_id, status, decided_by_user_id, decided_at, decision_note, created_at, updated_at
                from program_requests order by id
                on conflict do nothing");
        }
    }

    public function down(): void
    {
        // The two old tables were never touched: they still hold what they held.
        Schema::dropIfExists('approval_requests');
    }

    // Rows copied with their own ids leave the id counter behind: the next one goes after the highest.
    private function counterPastHighest(): void
    {
        DB::statement("select setval(pg_get_serial_sequence('approval_requests', 'id'), coalesce((select max(id) from approval_requests), 0) + 1, false)");
    }
};

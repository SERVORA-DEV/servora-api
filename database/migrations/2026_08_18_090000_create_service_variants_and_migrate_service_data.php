<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // old services.id => new service_variants.id, built while migrating rows,
    // reused by the branch_services/package_services retargeting passes.
    private array $oldServiceIdToVariantId = [];

    // services.id values that get folded away (their data now lives in
    // service_variants) — collected up front, only actually deleted at the
    // very end, once nothing still references them (see the ordering note
    // on deleteNonSurvivorServiceRows below).
    private array $nonSurvivorServiceIds = [];

    public function up(): void
    {
        Schema::create('service_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();

            $table->unsignedInteger('duration_minutes');
            $table->decimal('price', 10, 2);
            $table->decimal('commission_amount', 10, 2)->nullable();
            $table->unsignedInteger('loyalty_points')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['service_id', 'duration_minutes']);
        });

        $this->migrateServiceRowsIntoVariants();
        $this->retargetBranchServices();
        $this->retargetPackageServices();
        // Only now — after branch_services/package_services no longer
        // reference services.id at all — is it safe to delete the
        // non-survivor rows. services.id still cascadeOnDelete's into
        // branch_services/package_services.service_id up to this point, so
        // deleting any earlier would silently wipe out those rows' branch
        // availability / package line items before they got a chance to be
        // retargeted onto their new service_variant_id.
        $this->deleteNonSurvivorServiceRows();
        $this->dropVariantColumnsFromServices();
    }

    // Group every services row (soft-deleted included) by (business, name),
    // keep one survivor id per group, turn every row in the group into a
    // service_variants row pointed at the survivor. Does NOT delete the
    // non-survivor services rows yet — see the ordering note in up().
    private function migrateServiceRowsIntoVariants(): void
    {
        $rows = DB::table('services')->orderBy('id')->get();
        $groups = $rows->groupBy(fn ($row) => $row->spa_business_id . '|' . $row->name);

        foreach ($groups as $group) {
            $survivor = $group->firstWhere('deleted_at', null) ?? $group->sortBy('id')->first();

            foreach ($group as $row) {
                $variantId = DB::table('service_variants')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'service_id' => $survivor->id,
                    'duration_minutes' => $row->duration_minutes,
                    'price' => $row->default_price,
                    'commission_amount' => $row->default_commission_amount,
                    'loyalty_points' => $row->loyalty_points,
                    'is_active' => $row->is_active,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'deleted_at' => $row->deleted_at,
                ]);

                $this->oldServiceIdToVariantId[$row->id] = $variantId;
            }

            foreach ($group->pluck('id')->reject(fn ($id) => $id === $survivor->id) as $id) {
                $this->nonSurvivorServiceIds[] = $id;
            }
        }
    }

    private function deleteNonSurvivorServiceRows(): void
    {
        if ($this->nonSurvivorServiceIds) {
            DB::table('services')->whereIn('id', $this->nonSurvivorServiceIds)->delete();
        }
    }

    // Adds a new column + populates + drops the old one, rather than
    // renameColumn — this DB is MariaDB, and Laravel's doctrine-free native
    // rename path only covers MySQL 8.0.3+, so renameColumn falls back to a
    // legacy path that needs doctrine/dbal (not installed here) and errors.
    private function retargetBranchServices(): void
    {
        Schema::table('branch_services', function (Blueprint $table) {
            $table->unsignedBigInteger('service_variant_id')->nullable()->after('service_id');
        });

        foreach (DB::table('branch_services')->orderBy('id')->get() as $row) {
            DB::table('branch_services')->where('id', $row->id)
                ->update(['service_variant_id' => $this->oldServiceIdToVariantId[$row->service_id]]);
        }

        // The new composite unique goes in FIRST, before anything old is
        // dropped: (spa_branch_id, service_variant_id) has spa_branch_id as
        // its leading column, so it can immediately take over as the
        // supporting index for the spa_branch_id->spa_branches FK. Do this
        // in the other order — drop the old (spa_branch_id, service_id)
        // unique first — and MariaDB refuses the drop with "needed in a
        // foreign key constraint", because that composite unique was the
        // *only* index with spa_branch_id as a prefix and the FK would be
        // left without a supporting index, even though the FK itself has
        // nothing to do with service_id.
        Schema::table('branch_services', function (Blueprint $table) {
            $table->foreign('service_variant_id')->references('id')->on('service_variants')->cascadeOnDelete();
            $table->unique(['spa_branch_id', 'service_variant_id']);
        });

        // MariaDB batches every command from one Schema::table() closure into
        // a single ALTER TABLE statement and validates it as a whole — it
        // won't let a DROP INDEX through in the same statement as the DROP
        // FOREIGN KEY that frees it up, even listed first. Each step needs
        // its own statement.
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropUnique(['spa_branch_id', 'service_id']);
        });
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn('service_id');
        });

        // ->change() hits the same doctrine/dbal-dependent legacy path as
        // renameColumn on MariaDB — a raw MODIFY sidesteps it. Safe to run
        // after the data pass above already populated every row.
        DB::statement('ALTER TABLE branch_services MODIFY service_variant_id BIGINT UNSIGNED NOT NULL');
    }

    private function retargetPackageServices(): void
    {
        Schema::table('package_services', function (Blueprint $table) {
            $table->unsignedBigInteger('service_variant_id')->nullable()->after('service_id');
        });

        foreach (DB::table('package_services')->orderBy('id')->get() as $row) {
            DB::table('package_services')->where('id', $row->id)
                ->update(['service_variant_id' => $this->oldServiceIdToVariantId[$row->service_id]]);
        }

        // New composite unique goes in before the old one is dropped — same
        // "keep a supporting index for the package_id FK at all times"
        // reasoning as retargetBranchServices above.
        Schema::table('package_services', function (Blueprint $table) {
            $table->foreign('service_variant_id')->references('id')->on('service_variants')->cascadeOnDelete();
            $table->unique(['package_id', 'service_variant_id']);
        });

        Schema::table('package_services', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });
        Schema::table('package_services', function (Blueprint $table) {
            $table->dropUnique(['package_id', 'service_id']);
        });
        Schema::table('package_services', function (Blueprint $table) {
            $table->dropColumn('service_id');
        });

        DB::statement('ALTER TABLE package_services MODIFY service_variant_id BIGINT UNSIGNED NOT NULL');
    }

    private function dropVariantColumnsFromServices(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['spa_business_id', 'name', 'duration_minutes']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['duration_minutes', 'default_price', 'default_commission_amount', 'loyalty_points']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unique(['spa_business_id', 'name']);
        });
    }

    // Best-effort only: restores columns/FKs structurally, but a services row
    // that was merged away during up() cannot be un-merged from its variant
    // — do not rely on this for anything other than a same-session rollback
    // immediately after running up() in dev.
    public function down(): void
    {
        // Same "keep a spa_branch_id-prefixed index in place at all times"
        // ordering as up() — the new (spa_branch_id, service_id) unique goes
        // in before the old (spa_branch_id, service_variant_id) one is
        // dropped, or MariaDB refuses the drop as still needed by the
        // spa_branch_id FK.
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropForeign(['service_variant_id']);
        });
        Schema::table('branch_services', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->after('service_variant_id');
        });
        DB::statement('UPDATE branch_services SET service_id = service_variant_id');
        DB::statement('ALTER TABLE branch_services MODIFY service_id BIGINT UNSIGNED NOT NULL');
        // FK to services is deliberately not restored here — the copied
        // service_id values are former service_variants.id values, which
        // won't validate against services.id. Best-effort structural
        // rollback only, per the note above.
        Schema::table('branch_services', function (Blueprint $table) {
            $table->unique(['spa_branch_id', 'service_id']);
        });
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropUnique(['spa_branch_id', 'service_variant_id']);
        });
        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn('service_variant_id');
        });

        Schema::table('package_services', function (Blueprint $table) {
            $table->dropForeign(['service_variant_id']);
        });
        Schema::table('package_services', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->after('service_variant_id');
        });
        DB::statement('UPDATE package_services SET service_id = service_variant_id');
        DB::statement('ALTER TABLE package_services MODIFY service_id BIGINT UNSIGNED NOT NULL');
        Schema::table('package_services', function (Blueprint $table) {
            $table->unique(['package_id', 'service_id']);
        });
        Schema::table('package_services', function (Blueprint $table) {
            $table->dropUnique(['package_id', 'service_variant_id']);
        });
        Schema::table('package_services', function (Blueprint $table) {
            $table->dropColumn('service_variant_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['spa_business_id', 'name']);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('default_price', 10, 2)->nullable();
            $table->decimal('default_commission_amount', 10, 2)->nullable();
            $table->unsignedInteger('loyalty_points')->nullable();
            $table->unique(['spa_business_id', 'name', 'duration_minutes']);
        });

        Schema::dropIfExists('service_variants');
    }
};

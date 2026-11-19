<?php

namespace Tests\Feature;

use App\Models\CatalogRequest;
use App\Models\CustomerProgram;
use App\Models\ProgramRequest;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// approval_requests: what a manager asks the owner to approve — catalog requests and program
// requests in one table (2026_01_01_000800), each model seeing only its own rows. It took over
// from catalog_requests and program_requests. (The flows themselves: CatalogRequestTest,
// ProgramRequestTest.)
//
// Needs PostgreSQL (see phpunit.xml):
//   php artisan test --filter=ApprovalRequestsTest
class ApprovalRequestsTest extends TestCase
{
    use RefreshDatabase;

    private SpaBusiness $business;
    private SpaBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = SpaBusiness::factory()->create(['owner_id' => User::factory()->create(['role' => 'business_owner'])->id]);
        $this->branch = SpaBranch::factory()->create(['spa_business_id' => $this->business->id]);
    }

    private function base(): array
    {
        return ['spa_business_id' => $this->business->id, 'spa_branch_id' => $this->branch->id, 'name' => 'Hot Stone', 'status' => 'pending'];
    }

    public function test_each_model_sees_and_makes_only_its_own_rows(): void
    {
        $catalog = CatalogRequest::create($this->base() + ['kind' => 'service', 'category' => 'Massage', 'details' => 'Ninety minutes']);
        $program = ProgramRequest::create($this->base() + ['type' => 'voucher', 'payload' => ['values' => []], 'note' => 'For regulars']);

        $this->assertSame([CatalogRequest::SUBJECT, ProgramRequest::SUBJECT], DB::table('approval_requests')->orderBy('id')->pluck('subject')->all());
        $this->assertSame([$catalog->id], CatalogRequest::pluck('id')->all());
        $this->assertSame([$program->id], ProgramRequest::pluck('id')->all());
        $this->assertNull(CatalogRequest::where('uuid', $program->uuid)->first());
        $this->assertNull(ProgramRequest::where('uuid', $catalog->uuid)->first());

        // The old names read and write the shared columns.
        $this->assertSame('Ninety minutes', $catalog->fresh()->details);
        $this->assertSame('voucher', $program->fresh()->type);
        $this->assertSame(1, ProgramRequest::where('kind', 'voucher')->count());
        $this->assertSame(0, CatalogRequest::where('kind', 'voucher')->count());
    }

    // The switch: rows still in the two old tables are copied over — overlapping ids included —
    // and running it again changes nothing.
    public function test_the_migration_carries_both_kinds_over(): void
    {
        $program = CustomerProgram::forceCreate(['spa_business_id' => $this->business->id, 'type' => 'voucher', 'name' => 'Welcome', 'is_active' => true, 'audience' => 'all']);
        $now = now();
        $catalogUuid = (string) Str::uuid();
        $programUuid = (string) Str::uuid();
        DB::table('catalog_requests')->insert(['id' => 1, 'uuid' => $catalogUuid, 'spa_business_id' => $this->business->id, 'spa_branch_id' => $this->branch->id,
            'kind' => 'package', 'name' => 'Couples', 'category' => null, 'details' => 'Two people', 'status' => 'added', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('program_requests')->insert(['id' => 1, 'uuid' => $programUuid, 'spa_business_id' => $this->business->id, 'spa_branch_id' => $this->branch->id,
            'type' => 'voucher', 'name' => 'Welcome', 'payload' => json_encode(['values' => ['amount' => 100]]), 'note' => 'New clients',
            'status' => 'approved', 'customer_program_id' => $program->id, 'created_at' => $now, 'updated_at' => $now]);

        $migration = require database_path('migrations/2026_01_01_000800_create_approval_requests_table.php');
        $migration->up();
        $migration->up();

        $this->assertSame(2, DB::table('approval_requests')->count());

        $catalog = CatalogRequest::where('uuid', $catalogUuid)->sole();
        $this->assertSame(1, $catalog->id);
        $this->assertSame(['package', 'Couples', 'Two people', 'added'], [$catalog->kind, $catalog->name, $catalog->details, $catalog->status]);

        $copied = ProgramRequest::where('uuid', $programUuid)->sole();
        $this->assertNotSame(1, $copied->id);
        $this->assertSame(['voucher', 'Welcome', 'New clients', 'approved'], [$copied->type, $copied->name, $copied->note, $copied->status]);
        $this->assertSame(['values' => ['amount' => 100]], $copied->payload);
        $this->assertSame($program->id, $copied->program->id);

        // A new request after the switch takes the next free id.
        $next = CatalogRequest::create($this->base() + ['kind' => 'service']);
        $this->assertGreaterThan($copied->id, $next->id);

        // The old tables are left as they were.
        $this->assertSame(1, DB::table('catalog_requests')->count());
        $this->assertSame(1, DB::table('program_requests')->count());
    }
}

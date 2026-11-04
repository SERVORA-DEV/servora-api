<?php

namespace Tests\Feature;

use App\Http\Controllers\System\OwnerVerificationController;
use App\Models\AuditLog;
use App\Models\OwnerIdentityVerification;
use App\Models\OwnerVerificationReview;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Models\UserPermission;
use App\Repository\AuditLogRepository;
use App\Repository\SpaBusinessRepository;
use App\Repository\System\OwnerVerificationRepository;
use App\Service\NotificationService;
use App\Service\System\OwnerVerificationChecklistService;
use App\Service\System\OwnerVerificationService;
use App\Services\DocumentUploadService;
use App\Support\OwnerVerificationChecklist as Checklist;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class OwnerVerificationReviewTest extends TestCase
{
    private User $admin;
    private User $owner;
    private SpaBusiness $business;
    private OwnerIdentityVerification $identity;
    private OwnerVerificationChecklistService $reviews;
    private OwnerVerificationService $decisions;
    private NotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        // Only this workflow's schema, in a fresh in-memory database. Never
        // migrate, truncate, or connect to the application's configured DB.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('uuid')->unique();
            foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'role', 'audience'] as $key) $t->string($key)->nullable();
            $t->timestamps();
        });
        Schema::create('spa_businesses', function (Blueprint $t) {
            $t->id(); $t->string('uuid')->unique(); $t->foreignId('owner_id');
            foreach (['business_name', 'business_type', 'registration_document_type', 'registration_document_path', 'registered_business_name', 'registered_owner_name', 'authorized_representative_name', 'registration_number', 'verification_status', 'rejection_reason'] as $key) $t->string($key)->nullable();
            $t->foreignId('verified_by')->nullable(); $t->timestamp('verified_at')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('owner_identity_verifications', function (Blueprint $t) {
            $t->id(); $t->string('uuid')->unique(); $t->foreignId('user_id');
            foreach (['id_type', 'id_document_front_path', 'id_document_back_path', 'liveness_result', 'status', 'rejection_reason', 'rejected_field'] as $key) $t->string($key)->nullable();
            $t->json('face_scan_paths')->nullable(); $t->json('liveness_sequence')->nullable();
            $t->foreignId('verified_by')->nullable(); $t->timestamp('verified_at')->nullable(); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->nullable(); $t->string('table_name'); $t->unsignedBigInteger('record_id')->nullable();
            $t->string('action'); $t->json('old_values')->nullable(); $t->json('new_values')->nullable();
            $t->string('ip_address')->nullable(); $t->text('user_agent')->nullable(); $t->timestamp('created_at')->nullable();
        });
        (require database_path('migrations/2026_11_03_120000_create_owner_verification_reviews_table.php'))->up();

        $this->admin = User::create(['role' => 'system_administrator', 'first_name' => 'Review', 'last_name' => 'Admin']);
        $this->owner = User::create(['role' => 'business_owner', 'first_name' => 'Alex', 'last_name' => 'Owner', 'email' => 'owner@example.test']);
        $this->business = SpaBusiness::create([
            'owner_id' => $this->owner->id, 'business_name' => 'Review test spa', 'business_type' => 'Sole Proprietorship',
            'registration_document_type' => 'DTI', 'registration_document_path' => 'test/certificate',
            'registered_business_name' => 'Review test spa', 'registered_owner_name' => 'Alex Owner',
            'registration_number' => 'TEST-100', 'verification_status' => 'Pending',
        ]);
        $this->identity = OwnerIdentityVerification::create([
            'user_id' => $this->owner->id, 'id_type' => 'Drivers License',
            'id_document_front_path' => 'test/front', 'id_document_back_path' => 'test/back',
            'face_scan_paths' => ['test/face'], 'liveness_sequence' => ['center', 'left', 'right'],
            'liveness_result' => 'Passed', 'status' => 'Pending',
        ]);
        $repo = new OwnerVerificationRepository;
        $audit = new AuditLogRepository;
        $this->reviews = new OwnerVerificationChecklistService($repo, $audit);
        $this->notifications = Mockery::mock(NotificationService::class);
        $this->notifications->shouldReceive('ownerIdentityApproved', 'ownerBusinessApproved', 'ownerVerificationFullyApproved', 'ownerIdentityRejected', 'ownerBusinessRejected')->byDefault()->andReturnNull();
        $this->decisions = new OwnerVerificationService($repo, new SpaBusinessRepository, $audit, $this->notifications, $this->reviews);
        $documents = Mockery::mock(DocumentUploadService::class);
        $documents->shouldReceive('signedUrl')->andReturnUsing(fn ($path) => $path ? 'https://evidence.example.test/'.$path : null);
        app()->instance(DocumentUploadService::class, $documents);
    }

    private function snapshot(): array
    {
        return $this->reviews->forBusiness((new OwnerVerificationRepository)->findByUuid($this->business->uuid));
    }

    private function request(array $data): Request
    {
        $request = Request::create('/api/system/owner-verifications/'.$this->business->uuid, 'PUT', $data);
        $request->setUserResolver(fn () => $this->admin);
        return $request;
    }

    private function save(string $part, string $state = 'pass', string $reason = ''): array
    {
        $section = $this->snapshot()[$part];
        $checks = array_fill_keys(array_keys(Checklist::REQUIREMENTS[$part]), ['state' => $state, 'reason' => $reason]);
        return $this->reviews->save($this->admin, $this->business->uuid, $part, $this->request([
            'checks' => $checks, 'evidence_hash' => $section['evidence_hash'], 'revision' => $section['revision'],
        ]));
    }

    private function token(array $section): Request
    {
        return $this->request(['evidence_hash' => $section['evidence_hash'], 'revision' => $section['revision']]);
    }

    private function blocked(int $status, callable $callback): void
    {
        try { $callback(); }
        catch (ValidationException $e) { $this->assertSame($status, $e->status); return; }
        catch (HttpExceptionInterface $e) { $this->assertSame($status, $e->getStatusCode()); return; }
        $this->fail('The unsafe review write was not blocked.');
    }

    public function test_drafts_persist_separately_and_detail_response_binds_checks_to_the_same_evidence(): void
    {
        $identity = $this->save('identity', 'fail', 'Photo is unreadable.');
        $business = $this->save('business', 'pending');
        $this->assertSame('Photo is unreadable.', $this->snapshot()['identity']['checks']['id_valid']['reason']);
        $this->assertSame('pending', $this->snapshot()['business']['checks']['registration_valid']['state']);
        $this->assertCount(2, OwnerVerificationReview::all());
        $response = $this->decisions->getReview($this->business->uuid)->response()->getData(true);
        $this->assertSame($this->business->uuid, $response['data']['business']['uuid']);
        $this->assertSame($identity['evidence_hash'], $response['review_checklists']['identity']['evidence_hash']);
        $this->assertSame($business['checks'], $response['review_checklists']['business']['checks']);
        $draft = $this->save('identity', 'fail');
        $this->assertSame('', $draft['checks']['id_valid']['reason']);
        $this->blocked(422, fn () => $this->decisions->rejectIdentity($this->admin, $this->business->uuid, 'Fix it', 'both', $this->token($draft)));
    }

    public function test_checklist_input_cannot_add_requirements_omit_checks_or_invent_states(): void
    {
        $section = $this->snapshot()['identity'];
        foreach (['extra', 'missing', 'state'] as $invalid) {
            $checks = $section['checks'];
            if ($invalid === 'extra') $checks['invented'] = ['state' => 'pass'];
            if ($invalid === 'missing') unset($checks['face_match']);
            if ($invalid === 'state') $checks['id_valid']['state'] = 'approved';
            $this->blocked(422, fn () => $this->reviews->save($this->admin, $this->business->uuid, 'identity', $this->request([
                'checks' => $checks, 'evidence_hash' => $section['evidence_hash'], 'revision' => 0,
            ])));
        }
        $this->assertSame(0, OwnerVerificationReview::count());
    }

    public function test_approval_independently_requires_saved_passes_and_a_current_token(): void
    {
        $this->blocked(422, fn () => $this->decisions->approveIdentity($this->admin, $this->business->uuid));
        $this->blocked(422, fn () => $this->decisions->approveIdentity($this->admin, $this->business->uuid, $this->request([])));
        foreach (['pending', 'fail'] as $state) {
            $section = $this->save('identity', $state, $state === 'fail' ? 'Mismatch' : '');
            $this->assertFalse($section['eligible_for_approval']);
            $this->blocked(422, fn () => $this->decisions->approveIdentity($this->admin, $this->business->uuid, $this->token($section)));
        }
        $this->assertSame('Pending', $this->identity->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'Approve')->count());
    }

    public function test_missing_evidence_or_failed_liveness_blocks_even_a_fully_passed_checklist(): void
    {
        foreach ([['id_document_back_path' => null], ['face_scan_paths' => []], ['liveness_result' => 'Failed']] as $change) {
            $this->identity->update(array_merge(['id_document_back_path' => 'test/back', 'face_scan_paths' => ['test/face'], 'liveness_result' => 'Passed'], $change));
            $section = $this->save('identity');
            $this->assertNotEmpty($section['approval_blockers']);
            $this->blocked(422, fn () => $this->decisions->approveIdentity($this->admin, $this->business->uuid, $this->token($section)));
        }
        $this->identity->update(['id_type' => 'Passport', 'id_document_back_path' => null, 'liveness_result' => 'Passed']);
        $this->assertTrue($this->save('identity')['eligible_for_approval']);
        $this->business->update(['registration_document_path' => null]);
        $section = $this->save('business');
        $this->blocked(422, fn () => $this->decisions->approveBusiness($this->admin, $this->business->uuid, $this->token($section)));
        $this->assertSame('Pending', $this->business->fresh()->verification_status);
    }

    public function test_stale_versions_and_changed_evidence_cannot_overwrite_or_decide(): void
    {
        $old = $this->save('identity');
        $new = $this->save('identity', 'pending');
        $this->blocked(409, fn () => $this->decisions->approveIdentity($this->admin, $this->business->uuid, $this->token($old)));
        $this->blocked(409, fn () => $this->reviews->save($this->admin, $this->business->uuid, 'identity', $this->request([
            'checks' => $old['checks'], 'evidence_hash' => $old['evidence_hash'], 'revision' => $old['revision'],
        ])));
        $this->identity->update(['id_document_front_path' => 'test/replacement']);
        $this->assertSame(Checklist::empty('identity'), $this->snapshot()['identity']['checks']);
        $this->blocked(409, fn () => $this->decisions->approveIdentity($this->admin, $this->business->uuid, $this->token($new)));
        $this->assertSame('Pending', $this->identity->fresh()->status);
    }

    public function test_identity_approval_is_audited_once_and_preserves_independent_business_status(): void
    {
        $section = $this->save('identity');
        $this->notifications->shouldReceive('ownerIdentityApproved')->once();
        $this->notifications->shouldNotReceive('ownerVerificationFullyApproved');
        $this->decisions->approveIdentity($this->admin, $this->business->uuid, $this->token($section));
        $this->assertSame('Verified', $this->identity->fresh()->status);
        $this->assertSame('Pending', $this->business->fresh()->verification_status);
        $audit = AuditLog::where('action', 'Approve')->sole();
        $this->assertSame('approved', $audit->new_values['review_decision']);
        $this->assertSame($section['checks'], $audit->new_values['manual_checks']);
        $this->assertFalse($this->snapshot()['identity']['editable']);
        $this->blocked(409, fn () => $this->reviews->save($this->admin, $this->business->uuid, 'identity', $this->request([
            'checks' => $section['checks'], 'evidence_hash' => $section['evidence_hash'], 'revision' => $section['revision'],
        ])));
        $duplicate = $this->decisions->approveIdentity($this->admin, $this->business->uuid, $this->token($section));
        $this->assertSame(422, $duplicate->getStatusCode());
        $this->assertSame(1, AuditLog::where('action', 'Approve')->count());
    }

    public function test_business_can_still_be_approved_independently_of_identity(): void
    {
        $this->identity->update(['status' => 'Rejected']);
        $section = $this->save('business');
        $this->decisions->approveBusiness($this->admin, $this->business->uuid, $this->token($section));
        $this->assertSame('Verified', $this->business->fresh()->verification_status);
        $this->assertSame('Rejected', $this->identity->fresh()->status);
        $this->assertSame('spa_businesses', AuditLog::where('action', 'Approve')->sole()->table_name);
    }

    public function test_rejection_and_resubmission_require_explained_failures_and_keep_existing_statuses(): void
    {
        $passed = $this->save('identity');
        $this->blocked(422, fn () => $this->decisions->rejectIdentity($this->admin, $this->business->uuid, 'Reason', 'both', $this->token($passed)));
        $failed = $this->save('identity', 'fail', 'Unreadable ID.');
        $this->decisions->rejectIdentity($this->admin, $this->business->uuid, 'Please replace the ID.', 'id_document', $this->token($failed), true);
        $this->assertSame('Rejected', $this->identity->fresh()->status);
        $this->assertSame('id_document', $this->identity->fresh()->rejected_field);
        $this->assertSame('resubmission_requested', $this->snapshot()['identity']['decision']);
        $business = $this->save('business', 'fail', 'Certificate is incomplete.');
        $this->decisions->rejectBusiness($this->admin, $this->business->uuid, 'Upload the full certificate.', $this->token($business));
        $this->assertSame('Rejected', $this->business->fresh()->verification_status);
        $this->assertSame('rejected', $this->snapshot()['business']['decision']);
        $this->assertSame(2, AuditLog::where('action', 'Reject')->count());
        $this->identity->refresh()->update(['status' => 'Pending']); // Owner resubmits even identical evidence.
        $this->assertSame(Checklist::empty('identity'), $this->snapshot()['identity']['checks']);
        $this->blocked(409, fn () => $this->decisions->approveIdentity($this->admin, $this->business->uuid, $this->token($failed)));
    }

    public function test_audit_failure_rolls_back_both_status_and_review_decision(): void
    {
        $section = $this->save('identity');
        $audit = Mockery::mock(AuditLogRepository::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit write unavailable'));
        $service = new OwnerVerificationService(new OwnerVerificationRepository, new SpaBusinessRepository, $audit, $this->notifications, $this->reviews);
        try { $service->approveIdentity($this->admin, $this->business->uuid, $this->token($section)); $this->fail('Expected audit failure'); }
        catch (\RuntimeException $e) { $this->assertSame('Audit write unavailable', $e->getMessage()); }
        $this->assertSame('Pending', $this->identity->fresh()->status);
        $this->assertSame($section['revision'], $this->snapshot()['identity']['revision']);
        $this->assertNull($this->snapshot()['identity']['decision']);
    }

    public function test_review_routes_preserve_authentication_and_distinct_permissions(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/system/owner-verifications/'));
        $this->assertCount(8, $routes);
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            foreach (['auth:sanctum', 'role:system_administrator', 'admin.ip', 'admin.2fa'] as $gate) $this->assertContains($gate, $middleware);
            $permission = str_ends_with($route->uri(), '/approve') ? 'spa_business_approve'
                : (str_ends_with($route->uri(), '/reject') || str_ends_with($route->uri(), '/request-resubmission') ? 'spa_business_reject' : 'spa_business_view');
            $this->assertContains('permission:'.$permission, $middleware);
        }
        $this->admin->setRelation('permission', new UserPermission(['spa_business_view' => true, 'spa_business_approve' => false, 'spa_business_reject' => false]));
        $this->blocked(403, fn () => (new OwnerVerificationController($this->decisions))->saveChecklist($this->request([]), $this->business->uuid, 'identity', $this->reviews));
    }
}

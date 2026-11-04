<?php

namespace App\Service\System;

use App\Models\OwnerVerificationReview;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\System\OwnerVerificationRepository;
use App\Support\OwnerVerificationChecklist as Checklist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OwnerVerificationChecklistService
{
    public function __construct(
        private OwnerVerificationRepository $repository,
        private AuditLogRepository $audit,
    ) {}

    public function forBusiness(SpaBusiness $business): array
    {
        return ['identity' => $this->section($business, 'identity'), 'business' => $this->section($business, 'business')];
    }

    private function row(SpaBusiness $business, string $part): ?OwnerVerificationReview
    {
        return OwnerVerificationReview::where('spa_business_id', $business->id)->where('part', $part)->first();
    }

    private function current(SpaBusiness $business, string $part, ?OwnerVerificationReview $row): bool
    {
        return $row && hash_equals($row->evidence_hash, Checklist::fingerprint($business, $part))
            && ! (Checklist::status($business, $part) === 'Pending' && $row->decision !== null);
    }

    public function section(SpaBusiness $business, string $part): array
    {
        $row = $this->row($business, $part);
        $current = $this->current($business, $part, $row);
        $checks = $current ? $row->checks : Checklist::empty($part);
        $blockers = Checklist::blockers($business, $part);
        return [
            'requirements' => collect(Checklist::REQUIREMENTS[$part])->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'evidence_hash' => Checklist::fingerprint($business, $part),
            'revision' => $row?->revision ?? 0,
            'checks' => $checks,
            'editable' => Checklist::status($business, $part) === 'Pending',
            'approval_blockers' => $blockers,
            'eligible_for_approval' => $current && ! $blockers && Checklist::allPassed($part, $checks),
            'decision' => $current ? $row?->decision : null,
            'saved_at' => $current ? $row?->updated_at?->toIso8601String() : null,
        ];
    }

    public function save(User $admin, string $uuid, string $part, Request $request): array
    {
        $rules = [
            'evidence_hash' => 'required|string|size:64',
            'revision' => 'required|integer|min:0',
            'checks' => 'required|array:'.implode(',', array_keys(Checklist::REQUIREMENTS[$part])),
        ];
        foreach (Checklist::REQUIREMENTS[$part] as $key => $_label) {
            $rules["checks.$key"] = 'required|array:state,reason';
            $rules["checks.$key.state"] = 'required|string|in:pending,pass,fail';
            // An incomplete failure explanation can be saved as a draft, but
            // final rejection/resubmission independently requires every reason.
            $rules["checks.$key.reason"] = 'nullable|string|max:1000';
        }
        $data = $request->validate($rules);

        return DB::transaction(function () use ($admin, $uuid, $part, $request, $data) {
            $business = $this->repository->findForReviewUpdate($uuid);
            abort_unless(Checklist::status($business, $part) === 'Pending', 409, 'This verification has already changed. Reload the review.');
            $row = $this->row($business, $part);
            $this->assertVersion($business, $part, $row, $data);
            $checks = [];
            foreach (Checklist::REQUIREMENTS[$part] as $key => $_label) {
                $check = $data['checks'][$key];
                $checks[$key] = ['state' => $check['state'], 'reason' => $check['state'] === 'fail' ? trim($check['reason'] ?? '') : ''];
            }
            $before = $row?->only(['checks', 'revision', 'evidence_hash']);
            $row ??= new OwnerVerificationReview(['spa_business_id' => $business->id, 'part' => $part]);
            $row->fill([
                'checks' => $checks, 'evidence_hash' => $data['evidence_hash'],
                'revision' => ($row->revision ?? 0) + 1, 'reviewed_by' => $admin->id, 'decision' => null,
            ])->save();
            $this->audit->record($admin->id, 'owner_verification_reviews', $row->id, 'Update', $before, $row->only(['part', 'checks', 'revision', 'evidence_hash']), $request);
            return $this->section($business, $part);
        }, 3);
    }

    private function assertVersion(SpaBusiness $business, string $part, ?OwnerVerificationReview $row, array $data): void
    {
        abort_unless(hash_equals(Checklist::fingerprint($business, $part), $data['evidence_hash'])
            && ($row?->revision ?? 0) === (int) $data['revision'], 409,
            'The evidence or checklist changed in another session. Reload the review before continuing.');
    }

    public function authorizeDecision(SpaBusiness $business, string $part, bool $approve, ?Request $request): OwnerVerificationReview
    {
        if (! $request) {
            throw ValidationException::withMessages(['review' => 'A saved verification checklist is required.']);
        }
        $data = $request->validate(['evidence_hash' => 'required|string|size:64', 'revision' => 'required|integer|min:1']);
        $row = $this->row($business, $part);
        $this->assertVersion($business, $part, $row, $data);
        abort_unless($row && $this->current($business, $part, $row) && $row->decision === null, 409, 'Save the current evidence checklist before deciding.');

        if ($approve) {
            $blockers = Checklist::blockers($business, $part);
            if ($blockers || ! Checklist::allPassed($part, $row->checks)) {
                throw ValidationException::withMessages(['review' => $blockers ?: ['Every required manual check must pass before approval.']]);
            }
        } elseif (! Checklist::failuresExplained($part, $row->checks)) {
            throw ValidationException::withMessages(['review' => 'Mark at least one requirement as failed and explain every failed requirement.']);
        }
        return $row;
    }

    public function recordDecision(OwnerVerificationReview $row, User $admin, string $decision): array
    {
        $row->fill(['decision' => $decision, 'reviewed_by' => $admin->id, 'revision' => $row->revision + 1])->save();
        return ['manual_checks' => $row->checks, 'review_decision' => $decision,
            'review_revision' => $row->revision, 'evidence_hash' => $row->evidence_hash];
    }
}

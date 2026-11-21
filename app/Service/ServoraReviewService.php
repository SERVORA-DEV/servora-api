<?php

namespace App\Service;

use App\Enums\ServoraReviewStatus;
use App\Enums\VerificationStatus;
use App\Exceptions\ActionRefused;
use App\Exceptions\NotSetUp;
use App\Models\ServoraReview;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Repository\AuditLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\System\AdminUsersRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

// Owners rate SERVORA (the profile menu's "Rate SERVORA"). Only an owner of a
// verified business can write one, so each review is from a real, checked
// spa, and it goes on the public Reviews page at once (an edit too, marked
// "Edited"). Administrators are told of each one and can hide it; nothing is
// deleted. A hidden review stays hidden when its owner edits it.
//
// Reviews used to wait for an administrator to publish them: any still marked
// Pending from then are shown as well. Edits that were waiting beside a live
// review (pending_*) are still handled in setStatus.
class ServoraReviewService
{
    private const PUBLIC_CACHE = 'servora-reviews:public:v2';

    public function __construct(
        private AuditLogRepository $auditLogs,
        private NotificationRepository $notifications,
        private AdminUsersRepository $admins,
    ) {}

    // ── Owner ──

    public function mine(User $owner)
    {
        if (! $this->ready()) {
            return response()->json(['data' => null, 'available' => false]);
        }
        $review = ServoraReview::where('user_id', $owner->id)->first();

        return response()->json(['data' => $review ? $this->presentOwn($review) : null, 'available' => true, 'can_write' => (bool) $this->verifiedBusiness($owner)]);
    }

    public function save(User $owner, array $data, ?Request $request = null)
    {
        throw_unless($this->ready(), NotSetUp::class, 'Reviews are not set up on this server yet.');
        $business = $this->verifiedBusiness($owner);
        throw_unless($business, ActionRefused::class, 'You can rate SERVORA once your business is verified.');

        $review = ServoraReview::firstOrNew(['user_id' => $owner->id]);
        $before = $review->exists ? $review->only(['rating', 'title', 'body', 'status']) : null;
        $wasShown = $review->exists && in_array($review->status, [ServoraReviewStatus::Published->value, ServoraReviewStatus::Pending->value], true);
        $hidden = $review->exists && $review->status === ServoraReviewStatus::Hidden->value;

        $review->fill([
            'spa_business_id' => $business->id,
            'rating' => (int) $data['rating'],
            'title' => trim((string) ($data['title'] ?? '')) ?: null,
            'body' => trim($data['body']),
            'show_business' => (bool) ($data['show_business'] ?? true),
            // On the page at once; one an administrator hid stays hidden.
            'status' => $hidden ? ServoraReviewStatus::Hidden->value : ServoraReviewStatus::Published->value,
            'published_at' => $hidden ? $review->published_at : ($review->published_at ?? now()),
        ]);
        if ($this->editsReady()) {
            // An edit replaces the words on the page straight away.
            $review->fill([
                'pending_rating' => null, 'pending_title' => null, 'pending_body' => null, 'pending_show_business' => null, 'pending_at' => null,
                'edited_at' => $wasShown ? now() : $review->edited_at,
            ]);
        }
        $review->save();
        Cache::forget(self::PUBLIC_CACHE);

        $this->auditLogs->record($owner->id, 'servora_reviews', $review->id, $before ? 'Update' : 'Create', $before, $review->only(['rating', 'title', 'body', 'status']), $request);
        if (! $hidden) {
            $verb = $before ? 'edited their review of SERVORA' : 'rated SERVORA';
            foreach ($this->admins->allAdministrators() as $admin) {
                $this->notifications->create($admin->id, 'New SERVORA Review', "\"{$business->business_name}\" {$verb} ({$review->rating} of 5). It is on the Reviews page now; hide it there if it breaks the rules.", 'System', $review->uuid);
            }
        }

        return response()->json([
            'data' => $this->presentOwn($review),
            'message' => $hidden
                ? 'Saved. Your review was hidden by the SERVORA team, so it isn’t on our Reviews page.'
                : 'Thank you! Your review is now on our Reviews page.',
        ]);
    }

    // ── Public ──

    public function published()
    {
        if (! $this->ready()) {
            return response()->json(['data' => [], 'summary' => ['average' => null, 'count' => 0, 'stars' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]]]);
        }

        return response()->json(Cache::remember(self::PUBLIC_CACHE, 300, function () {
            // Published, and any still waiting from when reviews had to be checked first.
            $rows = ServoraReview::query()
                ->whereIn('status', [ServoraReviewStatus::Published->value, ServoraReviewStatus::Pending->value])
                ->with(['user:id,first_name,last_name,profile_photo', 'business:id,business_name,created_at'])
                ->orderByRaw('COALESCE(published_at, updated_at) DESC')
                ->limit(300)
                ->get();

            $branches = SpaBranch::query()
                ->whereIn('spa_business_id', $rows->pluck('spa_business_id')->filter()->unique())
                ->selectRaw('spa_business_id, COUNT(*) as n')
                ->groupBy('spa_business_id')
                ->pluck('n', 'spa_business_id');

            $stars = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
            foreach ($rows as $r) {
                $stars[$r->rating] = ($stars[$r->rating] ?? 0) + 1;
            }

            return [
                'summary' => [
                    'average' => $rows->count() ? round($rows->avg('rating'), 1) : null,
                    'count' => $rows->count(),
                    'stars' => $stars,
                ],
                'data' => $rows->map(fn (ServoraReview $r) => [
                    'uuid' => $r->uuid,
                    'rating' => $r->rating,
                    'title' => $r->title,
                    'body' => $r->body,
                    // First name and last initial only.
                    'author' => $this->shortName($r->user),
                    // Their profile picture, when they have one (initials otherwise).
                    'photo' => \App\Support\ProfilePhoto::url($r->user?->profile_photo),
                    'business' => $r->show_business ? $r->business?->business_name : null,
                    'branches' => $r->show_business ? (int) ($branches[$r->spa_business_id] ?? 0) : null,
                    'owner_since' => $r->business?->created_at?->toDateString(),
                    'published_at' => ($r->published_at ?? $r->updated_at)?->toIso8601String(),
                    'edited_at' => $this->editsReady() ? $r->edited_at?->toIso8601String() : null,
                ])->values()->all(),
            ];
        }));
    }

    // ── System administrator ──

    public function adminList(string $status)
    {
        throw_unless($this->ready(), NotSetUp::class, 'Reviews are not set up on this server yet.');
        $rows = ServoraReview::query()
            ->with(['user:id,first_name,last_name,email', 'business:id,uuid,business_name'])
            ->when($status === ServoraReviewStatus::Pending->value && $this->editsReady(), fn ($q) => $q->where(fn ($w) => $w->where('status', ServoraReviewStatus::Pending->value)->orWhereNotNull('pending_at')))
            ->when($status === ServoraReviewStatus::Pending->value && ! $this->editsReady(), fn ($q) => $q->where('status', ServoraReviewStatus::Pending->value))
            ->when(! in_array($status, ['All', ServoraReviewStatus::Pending->value], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')
            ->limit(300)
            ->get();

        return response()->json(['data' => $rows->map(fn (ServoraReview $r) => [
            'uuid' => $r->uuid,
            'rating' => $r->rating,
            'title' => $r->title,
            'body' => $r->body,
            'show_business' => $r->show_business,
            'status' => $r->status,
            'owner' => $r->user ? trim("{$r->user->first_name} {$r->user->last_name}") : null,
            'owner_email' => $r->user?->email,
            'public_name' => $this->shortName($r->user),
            'business' => $r->business?->business_name,
            'business_uuid' => $r->business?->uuid,
            'updated_at' => $r->updated_at?->toIso8601String(),
            'published_at' => $r->published_at?->toIso8601String(),
            // An edit of the published review, waiting (the words above are what's live).
            'edit' => $this->editsReady() && $r->hasPendingEdit() ? [
                'rating' => $r->pending_rating,
                'title' => $r->pending_title,
                'body' => $r->pending_body,
                'show_business' => (bool) $r->pending_show_business,
                'at' => $r->pending_at?->toIso8601String(),
            ] : null,
            'edited_at' => $this->editsReady() ? $r->edited_at?->toIso8601String() : null,
        ])->values()]);
    }

    public function setStatus(User $admin, string $uuid, string $status, ?Request $request = null)
    {
        // The status, its audit entry and the owner's notice are saved together, or not at all.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($admin, $uuid, $status, $request) {
            $review = ServoraReview::where('uuid', $uuid)->firstOrFail();

            // A waiting edit of a live review: publishing puts the edit up (marked
            // Edited); hiding turns the edit down and leaves the earlier words up.
            if ($this->editsReady() && $review->hasPendingEdit()) {
                $clear = ['pending_rating' => null, 'pending_title' => null, 'pending_body' => null, 'pending_show_business' => null, 'pending_at' => null, 'reviewed_by' => $admin->id, 'reviewed_at' => now()];
                if ($status === ServoraReviewStatus::Published->value) {
                    $review->update([
                        'rating' => $review->pending_rating,
                        'title' => $review->pending_title,
                        'body' => $review->pending_body,
                        'show_business' => (bool) $review->pending_show_business,
                        'status' => ServoraReviewStatus::Published->value,
                        'edited_at' => now(),
                    ] + $clear);
                    $this->notifications->create($review->user_id, 'Your Review Is Live', 'Your edit is now on our Reviews page.', 'System');
                } else {
                    $review->update($clear);
                    $this->notifications->create($review->user_id, 'Review Edit Not Published', 'Your edit wasn\'t published. Your earlier review stays on our Reviews page.', 'System');
                }
                Cache::forget(self::PUBLIC_CACHE);
                $this->auditLogs->record($admin->id, 'servora_reviews', $review->id, $status === ServoraReviewStatus::Published->value ? 'Approve' : 'Reject', null, ['status' => $review->status], $request);

                return response()->json(['message' => $status === ServoraReviewStatus::Published->value ? 'Edit published.' : 'Edit declined. The earlier review stays up.']);
            }

            $before = $review->status;
            $review->update([
                'status' => $status,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'published_at' => $status === ServoraReviewStatus::Published->value ? ($review->published_at ?? now()) : $review->published_at,
            ]);
            Cache::forget(self::PUBLIC_CACHE);
            $this->auditLogs->record($admin->id, 'servora_reviews', $review->id, $status === ServoraReviewStatus::Published->value ? 'Approve' : 'Deactivate', ['status' => $before], ['status' => $status], $request);

            if ($status === ServoraReviewStatus::Published->value && $before !== ServoraReviewStatus::Published->value) {
                $this->notifications->create($review->user_id, 'Your Review Is Live', 'Thank you for rating SERVORA. Your review is now on our Reviews page.', 'System');
            }

            return response()->json(['message' => $status === ServoraReviewStatus::Published->value ? 'Published.' : 'Hidden from the Reviews page.']);
        });
    }

    // ── internals ──

    private function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('servora_reviews');
    }

    private function verifiedBusiness(User $owner): ?SpaBusiness
    {
        return SpaBusiness::where('owner_id', $owner->id)->where('verification_status', VerificationStatus::Verified->value)->first();
    }

    private function shortName(?User $u): string
    {
        if (! $u) {
            return 'A SERVORA owner';
        }
        $last = trim((string) $u->last_name);

        return trim($u->first_name . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : ''));
    }

    // The owner sees their latest words: a waiting edit if there is one.
    private function presentOwn(ServoraReview $r): array
    {
        $editing = $this->editsReady() && $r->hasPendingEdit();

        return [
            'uuid' => $r->uuid,
            'rating' => $editing ? $r->pending_rating : $r->rating,
            'title' => $editing ? $r->pending_title : $r->title,
            'body' => $editing ? $r->pending_body : $r->body,
            'show_business' => $editing ? (bool) $r->pending_show_business : $r->show_business,
            'status' => $r->status,
            'edit_waiting' => $editing,
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }

    // The columns for edits come with their own migration.
    private function editsReady(): bool
    {
        static $ready = null;

        return $ready ??= $this->ready() && Schema::hasColumn('servora_reviews', 'pending_at');
    }
}

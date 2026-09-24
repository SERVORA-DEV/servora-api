<?php

namespace App\Models;

use App\Services\ImageUploadService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpaBranch extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'uuid',
        'spa_business_id',

        'branch_name',
        'code',

        'email',
        'phone_number',
        'facebook_url',
        'instagram_handle',
        'website_url',

        'latitude',
        'longitude',
        'formatted_address',

        'cover_photo',

        'permit_document_path',
        'permit_number',
        'permit_business_name',
        'permit_branch_location',
        'permit_issue_date',
        'permit_expiration_date',
        'permit_confirmed',

        'description',

        'verification_status',
        'operating_status',

        'listing_visible',
        'promo_text',
        'highlights',
        'display_settings',
        'booking_overrides',

        'closure_note',
        'reopens_at',

        'verified_by',
        'verified_at',

        'rejection_reason',
        'suspension_reason',
    ];

    // Client-app display options for this branch's listing, used for any
    // key the owner hasn't saved (Branch Settings → Marketplace).
    public const DISPLAY_DEFAULTS = [
        'show_prices' => true,
        'show_therapist_profiles' => true,
        'show_available_slots' => true,
        'show_room_availability' => false,
        'allow_online_payment' => false,
        'allow_promo_codes' => true,
        'show_reviews' => true,
        'show_rating_badge' => true,
        'show_review_photos' => true,
        'review_sort' => 'newest',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',

            'reopens_at' => 'date',
            'verified_at' => 'datetime',

            'permit_issue_date' => 'date',
            'permit_expiration_date' => 'date',
            'permit_confirmed' => 'boolean',

            'listing_visible' => 'boolean',
            'highlights' => 'array',
            'display_settings' => 'array',
            'booking_overrides' => 'array',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function schedules()
    {
        return $this->hasMany(BranchSchedule::class, 'spa_branch_id');
    }

    // Any account (Manager or Front Officer) assigned to a staff member at
    // this branch — reached transitively via Staff.user_id now rather than
    // a branch-owned pivot row (see Staff::user, User::staff).
    public function assignedAccount()
    {
        return $this->hasOneThrough(
            User::class,
            Staff::class,
            'spa_branch_id',
            'id',
            'id',
            'user_id'
        );
    }

    // The branch's Manager specifically — a branch's staff can hold either
    // a Manager or a Front Desk account (see assignedAccount()), so that
    // alone isn't reliable for "who manages this branch"; this filters
    // through to the manager-role staff member's account only.
    public function manager()
    {
        return $this->hasOneThrough(
            User::class,
            Staff::class,
            'spa_branch_id',
            'id',
            'id',
            'user_id'
        )->where('staff.role', 'manager');
    }

    public function branchServices()
    {
        return $this->hasMany(BranchService::class, 'spa_branch_id');
    }

    public function branchPackages()
    {
        return $this->hasMany(BranchPackage::class, 'spa_branch_id');
    }

    public function staff()
    {
        return $this->hasMany(Staff::class, 'spa_branch_id');
    }

    public function facilities()
    {
        return $this->hasMany(Facility::class, 'spa_branch_id');
    }

    public function photos()
    {
        return $this->hasMany(SpaBranchPhoto::class, 'spa_branch_id')->orderBy('sort_order')->orderBy('id');
    }

    public function coverPhoto()
    {
        return $this->hasOne(SpaBranchPhoto::class, 'spa_branch_id')->where('is_cover', true);
    }

    // The gallery photo marked as cover; the older single cover_photo
    // (set in the branch wizard) when the gallery is empty.
    public function coverPhotoUrl(): ?string
    {
        return ImageUploadService::url($this->coverPhoto?->path ?? $this->cover_photo);
    }

    public function displaySettings(): array
    {
        $stored = $this->display_settings ?? [];
        $result = [];
        foreach (self::DISPLAY_DEFAULTS as $key => $default) {
            $result[$key] = array_key_exists($key, $stored) ? $stored[$key] : $default;
        }

        return $result;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// A card, GCash or Maya account the owner saved at checkout, as a reusable
// Xendit payment token — what auto-renewal charges (CheckoutService,
// SubscriptionRenewalService). Only the token and display details are kept;
// the card number stays with Xendit.
class BusinessPaymentMethod extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = ['CARD', 'GCASH', 'MAYA'];

    protected $fillable = [
        'uuid', 'spa_business_id', 'type', 'xendit_payment_token_id', 'label',
        'brand', 'last4', 'expiry_month', 'expiry_year', 'status', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'expiry_month' => 'integer', 'expiry_year' => 'integer'];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function business()
    {
        return $this->belongsTo(SpaBusiness::class, 'spa_business_id');
    }

    // The payments.payment_method value a charge on this method is recorded as.
    public function paymentsColumnValue(): string
    {
        return match ($this->type) {
            'GCASH' => 'GCash',
            'MAYA' => 'Maya',
            default => 'Credit Card',
        };
    }

    public function isExpired(): bool
    {
        if ($this->type !== 'CARD' || ! $this->expiry_month || ! $this->expiry_year) {
            return false;
        }

        return now()->startOfMonth()->gt(now()->setDate($this->expiry_year, $this->expiry_month, 1)->startOfMonth());
    }

    public function present(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'label' => $this->label,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'expiry' => $this->expiry_month && $this->expiry_year
                ? sprintf('%02d/%02d', $this->expiry_month, $this->expiry_year % 100)
                : null,
            'is_expired' => $this->isExpired(),
            'is_default' => $this->is_default,
            'status' => $this->status,
        ];
    }
}

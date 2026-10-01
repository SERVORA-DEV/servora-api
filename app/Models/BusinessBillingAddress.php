<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// The billing address the owner confirms on the subscription checkout — one
// per business, pre-filled from the business profile the first time.
class BusinessBillingAddress extends Model
{
    protected $fillable = [
        'spa_business_id', 'full_name', 'email', 'phone', 'line1', 'line2', 'city',
        'province', 'postal_code', 'country', 'is_business_purchase', 'business_name', 'tin',
    ];

    protected function casts(): array
    {
        return ['is_business_purchase' => 'boolean'];
    }

    public function present(): array
    {
        return $this->only($this->fillable) + ['is_saved' => $this->exists];
    }
}

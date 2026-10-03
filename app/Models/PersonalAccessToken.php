<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

// Sanctum writes last_used_at on EVERY authenticated request — one extra
// UPDATE round trip to the database each time. Login Sessions only needs it
// to the nearest few minutes, so a touch that changes nothing but
// last_used_at is skipped while the stored value is still recent. Any other
// change (name, abilities, revoking) saves as usual.
class PersonalAccessToken extends SanctumToken
{
    public const LAST_USED_RESOLUTION_SECONDS = 300;

    public function save(array $options = [])
    {
        $previous = $this->getOriginal('last_used_at');

        if ($this->exists
            && array_keys($this->getDirty()) === ['last_used_at']
            && $previous !== null
            && $this->asDateTime($previous)->gt(now()->subSeconds(self::LAST_USED_RESOLUTION_SECONDS))) {
            return true;
        }

        return parent::save($options);
    }
}

<?php

namespace Tests\Unit;

use App\Support\BusinessActivityDescriber as Describer;
use App\Support\ProfilePhoto;
use PHPUnit\Framework\TestCase;

// An owner's, manager's or front officer's own account settings (/business/account/*).
//
// No database.
//   php artisan test --filter=AccountSelfServiceTest
class AccountSelfServiceTest extends TestCase
{
    public function test_a_photo_is_kept_under_the_kind_of_account_it_belongs_to(): void
    {
        $this->assertSame('administrators/u1/profile', ProfilePhoto::folderFor('system_administrator', 'u1'));

        foreach (['business_owner', 'manager', 'front_officer', null] as $role) {
            $this->assertSame('staff-accounts/u1/profile', ProfilePhoto::folderFor($role, 'u1'));
        }
    }

    public function test_every_change_to_the_account_goes_in_the_audit_log(): void
    {
        foreach ([
            'SecurityController@enableTwoFactor', 'SecurityController@confirmTwoFactor', 'SecurityController@disableTwoFactor',
            'SecurityController@submitPersonalEmail', 'SecurityController@verifyPersonalEmail', 'SecurityController@resendPersonalEmailOtp',
            'SecurityController@revokeSession', 'SecurityController@revokeOtherSessions',
            'AccountProfileController@update', 'ProfilePhotoController@store', 'ProfilePhotoController@destroy',
        ] as $route) {
            $this->assertNotNull(Describer::entry($route), $route);
        }
    }
}

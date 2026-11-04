<?php

namespace Tests\Unit;

use App\Http\Requests\Business\Settings\BusinessIdentityRequest;
use App\Support\StaffSpecialty;
use PHPUnit\Framework\TestCase;

// SERVORA covers spa work only: what the lists offer stays one staff member with one client, in one place.
// Salon work (staff juggling several clients) is not something the system runs.
//
// No database.
//   php artisan test --filter=SpaOnlyListsTest
class SpaOnlyListsTest extends TestCase
{
    public function test_the_service_categories_are_spa_ones(): void
    {
        $categories = require __DIR__ . '/../../config/service_categories.php';

        $this->assertSame(['Massage', 'Facial', 'Body Treatment', 'Waxing', 'Couples', 'VIP', 'Wellness'], $categories);
    }

    public function test_the_salon_titles_are_gone_and_someone_left_with_none_is_a_therapist(): void
    {
        foreach (['Nail Technician', 'Nail Artist', 'Hair Stylist', 'Hair Colorist', 'Hair Treatment Specialist', 'Makeup Artist', 'Lash Technician', 'Brow Technician', 'Beautician'] as $title) {
            $this->assertNotContains($title, StaffSpecialty::ALL);
            $this->assertSame(['Therapist'], StaffSpecialty::forRole('therapist', [$title]), $title);
        }

        $this->assertSame(['Waxing Specialist'], StaffSpecialty::clean(['Hair Stylist', 'Waxing Specialist']));
    }

    public function test_the_business_types_are_spa_ones(): void
    {
        $this->assertSame(['Day Spa', 'Health Spa', 'Resort Spa', 'Wellness Center'], BusinessIdentityRequest::SPA_TYPES);
    }
}

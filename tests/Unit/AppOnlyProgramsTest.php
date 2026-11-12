<?php

namespace Tests\Unit;

use App\Support\AppOnlyPrograms;
use PHPUnit\Framework\TestCase;

// Customer programs (points, vouchers, discounts, memberships) are for
// clients who use the Servora app.
class AppOnlyProgramsTest extends TestCase
{
    public function test_an_app_user_can_join_and_use_programs(): void
    {
        $this->assertTrue(AppOnlyPrograms::allows(true));
        $this->assertTrue(AppOnlyPrograms::usesHeld(true));
        $this->assertNull(AppOnlyPrograms::refusal(true));
    }

    public function test_a_client_without_the_app_is_refused_with_the_reason(): void
    {
        $this->assertFalse(AppOnlyPrograms::allows(false));
        $this->assertSame(AppOnlyPrograms::MESSAGE, AppOnlyPrograms::refusal(false));
        $this->assertStringContainsString('Servora app users', AppOnlyPrograms::MESSAGE);
        $this->assertStringContainsString('sign up in the app', AppOnlyPrograms::MESSAGE);
    }

    public function test_no_client_at_all_counts_as_without_the_app(): void
    {
        $this->assertFalse(AppOnlyPrograms::allows(null));
        $this->assertSame(AppOnlyPrograms::MESSAGE, AppOnlyPrograms::refusal(null));
    }

    public function test_what_a_client_without_the_app_holds_is_frozen(): void
    {
        $this->assertFalse(AppOnlyPrograms::HELD_USABLE);
        $this->assertFalse(AppOnlyPrograms::usesHeld(false));
        $this->assertFalse(AppOnlyPrograms::usesHeld(null));
    }
}

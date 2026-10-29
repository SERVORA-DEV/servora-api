<?php

namespace Tests\Unit;

use App\Support\MobileRelease;
use Tests\TestCase;

// The mobile app version in .env: what the app is told to offer, and when it must update.
//
// No database.
//   php artisan test --filter=MobileReleaseTest
class MobileReleaseTest extends TestCase
{
    private function set(array $values): array
    {
        config(['mobile' => $values + ['latest_version' => null, 'latest_build' => 0, 'min_build' => 0, 'apk_url' => null]]);

        return (new MobileRelease())->current();
    }

    public function test_nothing_set_blocks_nobody(): void
    {
        $r = $this->set([]);

        $this->assertSame(0, $r['latest_build']);
        $this->assertSame(0, $r['min_build']);
        $this->assertNull($r['apk_url']);
        $this->assertNull($r['latest_version']);
    }

    public function test_the_env_values_are_what_the_app_is_told(): void
    {
        $r = $this->set(['latest_version' => '1.0.1', 'latest_build' => '4', 'apk_url' => 'https://example.com/a.apk']);

        $this->assertSame('1.0.1', $r['latest_version']);
        $this->assertSame(4, $r['latest_build']);
        $this->assertSame(0, $r['min_build']);
        $this->assertSame('https://example.com/a.apk', $r['apk_url']);
    }

    public function test_a_minimum_makes_older_builds_update(): void
    {
        $this->assertSame(3, $this->set(['latest_build' => 5, 'min_build' => 3])['min_build']);
    }

    public function test_the_minimum_can_never_pass_the_newest_build(): void
    {
        $this->assertSame(4, $this->set(['latest_build' => 4, 'min_build' => 99])['min_build']);
    }

    public function test_an_empty_link_is_no_link(): void
    {
        $this->assertNull($this->set(['apk_url' => ''])['apk_url']);
    }
}

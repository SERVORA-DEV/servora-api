<?php

namespace Tests\Unit;

use App\Support\ListingPolicy;
use Tests\TestCase;

// The administrator's marketplace listing policy: which listing items a
// branch must have before clients see it. These are the parts with no
// database behind them.
//
//   php artisan test --filter=ListingPolicyTest
class ListingPolicyTest extends TestCase
{
    public function test_nothing_stored_means_the_starting_policy(): void
    {
        $this->assertSame(ListingPolicy::DEFAULTS, ListingPolicy::normalize(null));
        $this->assertSame(ListingPolicy::DEFAULTS, ListingPolicy::normalize([]));
    }

    public function test_the_starting_policy_leaves_only_the_promo_banner_optional(): void
    {
        $this->assertSame(['cover_photo', 'description', 'highlights'], ListingPolicy::requiredKeys(ListingPolicy::DEFAULTS));
    }

    public function test_a_stored_choice_replaces_the_default_for_that_item_only(): void
    {
        $policy = ListingPolicy::normalize(['promo' => 'required', 'highlights' => 'optional']);

        $this->assertSame('required', $policy['promo']);
        $this->assertSame('optional', $policy['highlights']);
        $this->assertSame('required', $policy['cover_photo']);
        $this->assertSame('required', $policy['description']);
    }

    public function test_unknown_items_are_dropped_and_odd_values_are_optional(): void
    {
        $policy = ListingPolicy::normalize(['cover_photo' => 'yes please', 'made_up' => 'required']);

        $this->assertSame('optional', $policy['cover_photo']);
        $this->assertArrayNotHasKey('made_up', $policy);
        $this->assertSame(array_keys(ListingPolicy::DEFAULTS), array_keys($policy));
    }

    public function test_every_item_has_a_label_for_the_owner(): void
    {
        $this->assertSame(array_keys(ListingPolicy::DEFAULTS), array_keys(ListingPolicy::ITEMS));
    }

    public function test_nothing_required_means_no_extra_rules(): void
    {
        $this->assertSame([], ListingPolicy::requiredKeys(array_fill_keys(array_keys(ListingPolicy::DEFAULTS), 'optional')));
    }
}

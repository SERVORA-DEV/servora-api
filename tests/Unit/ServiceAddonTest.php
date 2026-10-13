<?php

namespace Tests\Unit;

use App\Support\ServiceAddon;
use PHPUnit\Framework\TestCase;

// Add-ons: which services one goes with, why one can't be added, and what it
// adds to its main service's time.
class ServiceAddonTest extends TestCase
{
    private function main(array $over = []): array
    {
        return $over + ['status' => 'In Progress', 'is_addon' => false, 'service_id' => 1, 'category' => 'Massage', 'name' => 'Swedish Massage'];
    }

    private function addon(array $over = []): array
    {
        return $over + ['id' => 7, 'name' => 'Hot Stone', 'is_active' => true, 'categories' => ['Massage'], 'all_services' => true, 'service_ids' => []];
    }

    public function test_an_add_on_goes_with_every_service_in_its_categories(): void
    {
        $this->assertTrue(ServiceAddon::appliesTo($this->addon(), 'Massage', 1));
        $this->assertTrue(ServiceAddon::appliesTo($this->addon(['categories' => ['Facial', 'Massage']]), 'Massage', 99));
        $this->assertTrue(ServiceAddon::appliesTo($this->addon(), ' massage ', 1));
    }

    public function test_an_add_on_never_goes_with_another_category(): void
    {
        $this->assertFalse(ServiceAddon::appliesTo($this->addon(), 'Nails', 1));
        $this->assertFalse(ServiceAddon::appliesTo($this->addon(['categories' => []]), 'Massage', 1));
    }

    public function test_a_service_with_no_category_takes_no_add_ons(): void
    {
        $this->assertFalse(ServiceAddon::appliesTo($this->addon(), null, 1));
        $this->assertFalse(ServiceAddon::appliesTo($this->addon(), '', 1));
    }

    public function test_narrowed_to_specific_services_it_goes_only_with_those(): void
    {
        $only = $this->addon(['all_services' => false, 'service_ids' => [1, 4]]);

        $this->assertTrue(ServiceAddon::appliesTo($only, 'Massage', 1));
        $this->assertTrue(ServiceAddon::appliesTo($only, 'Massage', 4));
        $this->assertFalse(ServiceAddon::appliesTo($only, 'Massage', 2));
        $this->assertFalse(ServiceAddon::appliesTo($only, 'Massage', null));
        // A ticked service that has since moved to another category no longer counts.
        $this->assertFalse(ServiceAddon::appliesTo($only, 'Nails', 1));
    }

    public function test_it_is_added_to_a_waiting_or_running_service(): void
    {
        $this->assertNull(ServiceAddon::problem($this->main(), $this->addon(), [], 'in_service', false));
        $this->assertNull(ServiceAddon::problem($this->main(['status' => 'Pending']), $this->addon(), [9], 'checked_in', false));
    }

    public function test_an_add_on_for_another_category_is_refused(): void
    {
        $this->assertSame(
            'Hot Stone isn\'t offered with Classic Manicure.',
            ServiceAddon::problem($this->main(['category' => 'Nails', 'name' => 'Classic Manicure']), $this->addon(), [], 'in_service', false),
        );
    }

    public function test_a_switched_off_add_on_is_refused(): void
    {
        $this->assertSame(
            'Hot Stone is switched off.',
            ServiceAddon::problem($this->main(), $this->addon(['is_active' => false]), [], 'in_service', false),
        );
    }

    public function test_the_same_add_on_is_not_added_twice(): void
    {
        $this->assertSame(
            'Hot Stone is already on Swedish Massage.',
            ServiceAddon::problem($this->main(), $this->addon(), [7], 'in_service', false),
        );
    }

    public function test_nothing_goes_on_a_finished_or_removed_service(): void
    {
        foreach (['Completed', 'Cancelled'] as $status) {
            $this->assertStringContainsString(
                'already finished or removed',
                ServiceAddon::problem($this->main(['status' => $status]), $this->addon(), [], 'in_service', false),
            );
        }
    }

    public function test_an_add_on_has_no_add_ons(): void
    {
        $this->assertSame(
            'An add-on can\'t have add-ons of its own.',
            ServiceAddon::problem($this->main(['is_addon' => true]), $this->addon(), [], 'in_service', false),
        );
    }

    public function test_a_billed_or_closed_visit_takes_nothing_more(): void
    {
        $this->assertStringContainsString('bill has already been made', ServiceAddon::problem($this->main(), $this->addon(), [], 'in_service', true));
        foreach (['cancelled', 'no_show', 'completed'] as $status) {
            $this->assertStringContainsString('closed', ServiceAddon::problem($this->main(), $this->addon(), [], $status, false));
        }
    }

    public function test_add_ons_lengthen_their_main_service(): void
    {
        $this->assertSame(60, ServiceAddon::totalMinutes(60, []));
        $this->assertSame(90, ServiceAddon::totalMinutes(60, [15, 15]));
        // An add-on that takes no time (an oil) adds none.
        $this->assertSame(75, ServiceAddon::totalMinutes(60, [15, 0, null]));
        $this->assertSame(30, ServiceAddon::addedMinutes([10, 20, -5]));
    }

    public function test_ticked_services_outside_the_categories_are_named(): void
    {
        $services = [1 => 'Massage', 2 => 'Nails', 3 => null, 4 => 'massage'];

        $this->assertSame([2, 3], ServiceAddon::outsideCategories(['Massage'], $services));
        $this->assertSame([], ServiceAddon::outsideCategories(['Massage', 'Nails'], [1 => 'Massage', 2 => 'Nails']));
    }
}

<?php

namespace Tests\Unit;

use App\Support\VoucherTarget;
use PHPUnit\Framework\TestCase;

// The rules behind "every 5th massage, 50% off a massage": which services a
// voucher is about, which visits count, and which bill line it comes off.
class VoucherTargetTest extends TestCase
{
    private const SWEDISH = ['uuid' => 'sw', 'category' => 'Massage'];
    private const HILOT = ['uuid' => 'hi', 'category' => 'Massage'];
    private const FACIAL = ['uuid' => 'fa', 'category' => 'Facial'];

    private function massage(): array
    {
        return VoucherTarget::of(['usableKind' => 'category', 'usableCategory' => 'Massage'], 'usable');
    }

    public function test_a_voucher_with_no_target_is_for_all_services(): void
    {
        $target = VoucherTarget::of(['dealType' => '% off', 'usableOn' => 'All services'], 'usable');

        $this->assertTrue(VoucherTarget::isAll($target));
        $this->assertTrue(VoucherTarget::matches($target, 'fa', 'Facial'));
        $this->assertTrue(VoucherTarget::matches($target, null, null));
    }

    public function test_a_half_set_target_reads_as_all_services(): void
    {
        $this->assertTrue(VoucherTarget::isAll(VoucherTarget::of(['usableKind' => 'category', 'usableCategory' => ' '], 'usable')));
        $this->assertTrue(VoucherTarget::isAll(VoucherTarget::of(['usableKind' => 'services', 'usableServices' => ' , '], 'usable')));
        $this->assertTrue(VoucherTarget::isAll(VoucherTarget::of(['usableKind' => 'something'], 'usable')));
        $this->assertTrue(VoucherTarget::isAll(VoucherTarget::of(null, 'earn')));
    }

    public function test_a_category_target_matches_services_in_that_category(): void
    {
        $target = $this->massage();

        $this->assertTrue(VoucherTarget::matches($target, 'sw', 'Massage'));
        $this->assertTrue(VoucherTarget::matches($target, 'sw', ' massage '));
        $this->assertFalse(VoucherTarget::matches($target, 'fa', 'Facial'));
        $this->assertFalse(VoucherTarget::matches($target, 'xx', null));
    }

    public function test_a_chosen_services_target_matches_only_those_services(): void
    {
        $target = VoucherTarget::of(['usableKind' => 'services', 'usableServices' => 'sw, fa,sw'], 'usable');

        $this->assertSame(['sw', 'fa'], $target['services']);
        $this->assertTrue(VoucherTarget::matches($target, 'sw', 'Massage'));
        $this->assertTrue(VoucherTarget::matches($target, 'fa', 'Facial'));
        $this->assertFalse(VoucherTarget::matches($target, 'hi', 'Massage'));
    }

    public function test_the_earn_and_use_targets_are_read_separately(): void
    {
        $values = ['usableKind' => 'category', 'usableCategory' => 'Facial', 'earnKind' => 'category', 'earnCategory' => 'Massage'];

        $this->assertSame('Facial', VoucherTarget::of($values, 'usable')['category']);
        $this->assertSame('Massage', VoucherTarget::of($values, 'earn')['category']);
    }

    public function test_the_discount_comes_off_the_dearest_matching_line(): void
    {
        $lines = [
            self::SWEDISH + ['price' => 800],
            self::FACIAL + ['price' => 2500],
            self::HILOT + ['price' => 1200],
        ];

        $this->assertSame('hi', VoucherTarget::priciest($this->massage(), $lines)['uuid']);
    }

    public function test_no_matching_line_means_nothing_to_use_it_on(): void
    {
        $this->assertNull(VoucherTarget::priciest($this->massage(), [self::FACIAL + ['price' => 2500]]));
        $this->assertNull(VoucherTarget::priciest($this->massage(), []));
        // A matching line that costs nothing has nothing to take off.
        $this->assertNull(VoucherTarget::priciest($this->massage(), [self::SWEDISH + ['price' => 0]]));
    }

    public function test_a_visit_counts_once_however_many_matching_services_it_had(): void
    {
        $visits = [
            [self::SWEDISH, self::HILOT],   // two massages: one visit
            [self::FACIAL],                 // no massage
            [self::FACIAL, self::SWEDISH],  // one of each
            [],                             // everything on it was cancelled
        ];

        $this->assertSame(2, VoucherTarget::visits($this->massage(), $visits));
        $this->assertSame(4, VoucherTarget::visits(VoucherTarget::of([], 'earn'), $visits));
    }

    public function test_what_each_deal_takes_off_the_line(): void
    {
        $this->assertSame(600.0, VoucherTarget::amountOff('% off', 50, 1200));
        $this->assertSame(1200.0, VoucherTarget::amountOff('% off', 150, 1200));
        $this->assertSame(200.0, VoucherTarget::amountOff('₱ off', 200, 1200));
        // Never more than the service costs.
        $this->assertSame(1200.0, VoucherTarget::amountOff('₱ off', 2000, 1200));
        $this->assertSame(1200.0, VoucherTarget::amountOff('Free service', 0, 1200));
    }

    public function test_the_wording(): void
    {
        $services = fn (string ...$uuids) => VoucherTarget::of(['usableKind' => 'services', 'usableServices' => implode(',', $uuids)], 'usable');

        $this->assertSame('All services', VoucherTarget::phrase(VoucherTarget::of([], 'usable')));
        $this->assertSame('a Massage service', VoucherTarget::phrase($this->massage()));
        $this->assertSame('Massage service', VoucherTarget::freePhrase($this->massage()));
        $this->assertSame('Swedish', VoucherTarget::phrase($services('a'), ['Swedish']));
        $this->assertSame('Swedish or Hilot', VoucherTarget::phrase($services('a', 'b'), ['Swedish', 'Hilot']));
        $this->assertSame('Swedish, Hilot or Hot Stone', VoucherTarget::phrase($services('a', 'b', 'c'), ['Swedish', 'Hilot', 'Hot Stone']));
        $this->assertSame('Swedish, Hilot or 3 others', VoucherTarget::phrase($services('a', 'b', 'c', 'd', 'e'), ['Swedish', 'Hilot', 'Hot Stone', 'Shiatsu', 'Thai']));
        $this->assertSame('Swedish or Hilot', VoucherTarget::freePhrase($services('a', 'b'), ['Swedish', 'Hilot']));
    }

    public function test_a_voucher_only_fits_a_booking_that_has_what_it_is_for(): void
    {
        $hilot = ['uuid' => 'hi', 'category' => 'Massage', 'name' => 'Hilot'];
        $facial = ['uuid' => 'fa', 'category' => 'Facial', 'name' => 'Classic Facial'];
        $chosen = VoucherTarget::of(['usableKind' => 'services', 'usableServices' => 'hi,sw'], 'usable');

        // A category or chosen services: one of the booked services must be covered
        // (a service inside a package counts: it is in the list too).
        $this->assertTrue(VoucherTarget::fits('Free service', $this->massage(), 'a Massage service', [$hilot, $facial]));
        $this->assertFalse(VoucherTarget::fits('Free service', $this->massage(), 'a Massage service', [$facial]));
        $this->assertTrue(VoucherTarget::fits('% off', $chosen, 'Hilot', [$hilot]));
        $this->assertFalse(VoucherTarget::fits('% off', $chosen, 'Hilot', [$facial]));
        $this->assertFalse(VoucherTarget::fits('Free service', $this->massage(), 'a Massage service', []));
    }

    public function test_an_older_free_service_voucher_is_found_by_its_name(): void
    {
        $footSpa = ['uuid' => 'fs', 'category' => 'Foot', 'name' => 'Foot Spa'];
        $facial = ['uuid' => 'fa', 'category' => 'Facial', 'name' => 'Classic Facial'];
        $all = VoucherTarget::of(['dealType' => 'Free service', 'usableOn' => 'Foot Spa'], 'usable');

        $this->assertTrue(VoucherTarget::fits('Free service', $all, 'Foot Spa', [$footSpa]));
        $this->assertFalse(VoucherTarget::fits('Free service', $all, 'Foot Spa', [$facial]));
        // A free service with no name to look for fits nothing (as at the bill).
        $this->assertFalse(VoucherTarget::fits('Free service', $all, '', [$footSpa]));
    }

    public function test_a_voucher_for_all_services_fits_any_booking(): void
    {
        $all = VoucherTarget::of(['dealType' => '% off', 'value' => '10', 'usableOn' => 'All services'], 'usable');

        $this->assertTrue(VoucherTarget::fits('% off', $all, 'All services', []));
        $this->assertTrue(VoucherTarget::fits('₱ off', $all, 'All services', [['uuid' => 'x', 'category' => null, 'name' => 'Anything']]));
    }
}

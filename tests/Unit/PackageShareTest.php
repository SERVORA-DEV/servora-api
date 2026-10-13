<?php

namespace Tests\Unit;

use App\Support\PackageShare;
use PHPUnit\Framework\TestCase;

// How much of a package's price goes with one of its services.
class PackageShareTest extends TestCase
{
    public function test_two_equal_services_split_the_package_in_half(): void
    {
        $this->assertSame(260.0, PackageShare::of(520.0, [1 => 300.0, 2 => 300.0], 1));
    }

    public function test_the_share_follows_the_list_price(): void
    {
        $prices = [1 => 300.0, 2 => 100.0];

        $this->assertSame(390.0, PackageShare::of(520.0, $prices, 1));
        $this->assertSame(130.0, PackageShare::of(520.0, $prices, 2));
    }

    public function test_voiding_one_after_another_ends_at_exactly_zero(): void
    {
        // Three services whose shares don't divide evenly.
        $price = 1000.0;
        $prices = [1 => 333.0, 2 => 333.0, 3 => 334.0];
        $given = 0.0;

        foreach ([2, 1, 3] as $line) {
            $share = PackageShare::of($price, $prices, $line);
            $given += $share;
            $price = round($price - $share, 2);
            unset($prices[$line]);
        }

        $this->assertSame(0.0, $price);
        $this->assertSame(1000.0, round($given, 2));
    }

    public function test_the_last_service_takes_whatever_is_left(): void
    {
        $this->assertSame(260.0, PackageShare::of(260.0, [2 => 300.0], 2));
    }

    public function test_services_with_no_list_price_share_equally(): void
    {
        $this->assertSame(250.0, PackageShare::of(500.0, [1 => 0.0, 2 => 0.0], 1));
    }

    public function test_a_service_that_is_not_on_the_package_takes_nothing(): void
    {
        $this->assertSame(0.0, PackageShare::of(520.0, [1 => 300.0, 2 => 300.0], 9));
    }
}

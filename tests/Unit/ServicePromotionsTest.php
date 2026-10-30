<?php

namespace Tests\Unit;

use App\Support\ServicePromotions;
use App\Support\VoucherTarget;
use PHPUnit\Framework\TestCase;

// Which vouchers and discounts cover a service, for the mark on the front desk's service menu.
//
// No database.
//   php artisan test --filter=ServicePromotionsTest
class ServicePromotionsTest extends TestCase
{
    private function voucher(array $values, string $name = 'V', ?string $freeNamed = null): array
    {
        return ['type' => 'voucher', 'name' => $name, 'label' => '20% off', 'target' => VoucherTarget::of($values, 'usable'), 'free_named' => $freeNamed, 'members_only' => false];
    }

    private function discount(string $appliesTo, string $name = 'D', bool $members = false): array
    {
        return ['type' => 'discount', 'name' => $name, 'label' => '10% off', 'applies_to' => $appliesTo, 'members_only' => $members];
    }

    public function test_a_discount_for_all_services_covers_everything(): void
    {
        $found = ServicePromotions::forService([$this->discount('All services')], 'u1', 'Massage', 'Swedish Massage');

        $this->assertCount(1, $found);
        $this->assertSame('discount', $found[0]['type']);
    }

    public function test_a_discount_naming_a_category_or_service_covers_only_that(): void
    {
        $promos = [$this->discount('Facial services only')];

        $this->assertCount(1, ServicePromotions::forService($promos, 'u1', 'Facial', 'Deep Cleanse'));
        $this->assertCount(0, ServicePromotions::forService($promos, 'u2', 'Massage', 'Swedish Massage'));
        $this->assertCount(1, ServicePromotions::forService([$this->discount('Swedish Massage')], 'u2', 'Massage', 'Swedish Massage'));
    }

    public function test_a_voucher_for_a_category_or_chosen_services(): void
    {
        $category = $this->voucher(['usableKind' => 'category', 'usableCategory' => 'Massage']);
        $chosen = $this->voucher(['usableKind' => 'services', 'usableServices' => 'u1,u3']);

        $this->assertCount(1, ServicePromotions::forService([$category], 'u2', 'massage', 'Swedish'));
        $this->assertCount(0, ServicePromotions::forService([$category], 'u2', 'Facial', 'Deep Cleanse'));
        $this->assertCount(1, ServicePromotions::forService([$chosen], 'u3', 'Facial', 'X'));
        $this->assertCount(0, ServicePromotions::forService([$chosen], 'u2', 'Facial', 'X'));
    }

    public function test_a_voucher_with_nothing_set_covers_all_services(): void
    {
        $this->assertCount(1, ServicePromotions::forService([$this->voucher([])], 'u1', 'Massage', 'Swedish'));
    }

    public function test_an_older_free_service_voucher_covers_only_the_service_it_names(): void
    {
        $promo = $this->voucher(['usableKind' => 'all'], 'Free', 'Foot Spa');

        $this->assertCount(1, ServicePromotions::forService([$promo], 'u1', 'Spa', 'foot spa'));
        $this->assertCount(0, ServicePromotions::forService([$promo], 'u2', 'Spa', 'Hand Spa'));
    }

    public function test_members_only_is_passed_on_and_several_can_apply(): void
    {
        $found = ServicePromotions::forService([$this->discount('All services', 'Member Rate', true), $this->voucher([], 'Welcome')], 'u1', 'Massage', 'Swedish');

        $this->assertSame(['Member Rate', 'Welcome'], array_column($found, 'name'));
        $this->assertTrue($found[0]['members_only']);
        $this->assertFalse($found[1]['members_only']);
    }
}

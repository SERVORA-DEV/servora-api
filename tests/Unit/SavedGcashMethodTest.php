<?php

namespace Tests\Unit;

use App\Models\BusinessPaymentMethod;
use PHPUnit\Framework\TestCase;

// A GCash account added by number has no Xendit token: it is approved each time it is
// used, so it is shown as such and is never something auto-renewal could charge.
class SavedGcashMethodTest extends TestCase
{
    public function test_a_gcash_with_no_token_pays_each_time(): void
    {
        $gcash = new BusinessPaymentMethod(['type' => 'GCASH', 'label' => 'GCash · •••• 4567', 'last4' => '4567', 'status' => 'active', 'xendit_payment_token_id' => null]);

        $this->assertTrue($gcash->paysEachTime());
        $shown = $gcash->present();
        $this->assertTrue($shown['pays_each_time']);
        $this->assertEmpty($shown['is_default']);
        $this->assertSame('GCASH', $shown['type']);
    }

    public function test_a_saved_card_or_maya_with_a_token_is_charged_by_itself(): void
    {
        $maya = new BusinessPaymentMethod(['type' => 'MAYA', 'label' => 'Maya', 'status' => 'active', 'xendit_payment_token_id' => 'pt-123']);

        $this->assertFalse($maya->paysEachTime());
        $this->assertFalse($maya->present()['pays_each_time']);
    }
}

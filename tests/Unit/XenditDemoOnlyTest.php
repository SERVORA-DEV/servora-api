<?php

namespace Tests\Unit;

use App\Service\XenditRequestException;
use App\Service\XenditService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// The demo lock: while XENDIT_DEMO_ONLY is on, nothing is sent to Xendit
// unless the key is a TEST key — so a live key cannot charge anyone by
// accident. No database needed.
class XenditDemoOnlyTest extends TestCase
{
    private function xendit(?string $key, bool $demoOnly = true): XenditService
    {
        config(['services.xendit.secret_key' => $key, 'services.xendit.demo_only' => $demoOnly, 'services.xendit.base_url' => 'https://api.xendit.co']);
        Http::fake(['*' => Http::response(['payment_session_id' => 'ps-1', 'components_sdk_key' => 'k', 'id' => 'inv-1', 'status' => 'PENDING'])]);

        return new XenditService();
    }

    public function test_a_test_key_is_used_as_usual(): void
    {
        $xendit = $this->xendit('xnd_development_abc');

        $this->assertTrue($xendit->isTestMode());
        $this->assertNull($xendit->blockedReason());
        $this->assertSame('ps-1', $xendit->createSession(['reference_id' => 'x'])['payment_session_id']);
        Http::assertSentCount(1);
    }

    public function test_a_live_key_sends_nothing_while_the_demo_lock_is_on(): void
    {
        $xendit = $this->xendit('xnd_production_abc');

        $this->assertFalse($xendit->isTestMode());
        $this->assertNotNull($xendit->blockedReason());

        foreach ([
            fn () => $xendit->createSession(['reference_id' => 'x']),
            fn () => $xendit->chargeToken('ref', 'pt-1', 500, 'Renewal', true),
            fn () => $xendit->getSession('ps-1'),
            fn () => $xendit->getPaymentRequest('pr-1'),
            fn () => $xendit->getPaymentToken('pt-1'),
            fn () => $xendit->cancelSession('ps-1'),
            fn () => $xendit->createInvoice('ext', 500, 'Plan', 'https://a.test/ok', 'https://a.test/no'),
            fn () => $xendit->getInvoiceByExternalId('ext'),
        ] as $call) {
            try {
                $call();
                $this->fail('A call went through with a live key.');
            } catch (XenditRequestException $e) {
                $this->assertSame('DEMO_ONLY', $e->errorCode);
            }
        }
        Http::assertNothingSent();
    }

    public function test_no_key_at_all_sends_nothing_either(): void
    {
        $xendit = $this->xendit(null);

        $this->expectException(XenditRequestException::class);
        try {
            $xendit->createSession(['reference_id' => 'x']);
        } finally {
            Http::assertNothingSent();
        }
    }

    // Going live is a deliberate change: the lock off AND a live key.
    public function test_the_lock_can_be_switched_off_on_purpose(): void
    {
        $xendit = $this->xendit('xnd_production_abc', demoOnly: false);

        $this->assertFalse($xendit->isTestMode());
        $this->assertNull($xendit->blockedReason());
        $xendit->createSession(['reference_id' => 'x']);
        Http::assertSentCount(1);
    }
}

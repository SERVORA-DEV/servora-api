<?php

namespace Tests\Unit;

use App\Support\BusinessActivityDescriber as Describer;
use PHPUnit\Framework\TestCase;

// The business Audit Log's wording and what it keeps out of a row. No
// database. (That every business write route has an entry is checked against
// the live route list — see the 2026_10_28 work's probe.)
class BusinessActivityDescriberTest extends TestCase
{
    // audit_logs.action is a database enum: every entry must use one of these.
    private const ACTIONS = [
        'Create', 'Update', 'Delete', 'Login', 'Logout', 'Register', 'Verify Email', 'Reset Password', 'Change Password',
        'Approve', 'Reject', 'Suspend', 'Restore', 'Assign', 'Unassign', 'Check In', 'Check Out', 'Complete', 'Cancel',
        'Refund', 'Redeem', 'Export', 'Activate', 'Deactivate', 'Upload', 'Submit', 'Replace', 'Login Failed',
    ];

    public function test_every_entry_is_well_formed(): void
    {
        foreach (Describer::MAP as $route => [$event, $area, $action, $class, $sentence]) {
            $this->assertMatchesRegularExpression('/^\w+Controller@\w+$/', $route);
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $event, $route);
            $this->assertContains($area, Describer::AREAS, $route);
            $this->assertContains($action, self::ACTIONS, $route);
            $this->assertNotSame('', trim($sentence), $route);
            if ($class !== null) {
                $this->assertTrue(class_exists($class), "{$route}: {$class}");
            }
            // A sentence names its record only inside an optional [part], so
            // it still reads when the record can't be named.
            $this->assertSame(0, preg_match('/\{subject\}(?![^\[]*\])/', $sentence), "{$route}: {subject} outside [ ]");
        }
    }

    public function test_skipped_routes_have_no_entry(): void
    {
        $this->assertNull(Describer::entry('NotificationController@markRead'));
        $this->assertNull(Describer::entry('ManagerNotificationPrefsController@update'));
        $this->assertSame('client.update', Describer::entry('ClientController@update')[0]);
    }

    public function test_an_unlisted_route_still_gets_a_row(): void
    {
        [$event, $area, $action, $class, $sentence] = Describer::entry('GiftCardController@archive');

        $this->assertSame('gift_card.archive', $event);
        $this->assertSame('settings', $area);
        $this->assertSame('Update', $action);
        $this->assertNull($class);
        $this->assertSame('Gift card: archive', $sentence);
        $this->assertSame('Create', Describer::fallback('GiftCardController@store')[2]);
    }

    public function test_fill_keeps_only_the_parts_it_can_name(): void
    {
        $this->assertSame('Took a payment of ₱500 from Ana Cruz', Describer::fill('Took a payment[ of {amount}][ from {subject}]', ['amount' => '₱500', 'subject' => 'Ana Cruz']));
        $this->assertSame('Took a payment from Ana Cruz', Describer::fill('Took a payment[ of {amount}][ from {subject}]', ['amount' => null, 'subject' => 'Ana Cruz']));
        $this->assertSame('Took a payment', Describer::fill('Took a payment[ of {amount}][ from {subject}]', []));
        $this->assertSame("Cancelled Ana's booking", Describer::fill("Cancelled[ {subject}'s] booking", ['subject' => 'Ana']));
        $this->assertSame('Cancelled booking', Describer::fill("Cancelled[ {subject}'s] booking", ['subject' => '']));
    }

    public function test_clean_drops_secrets_ids_and_book_keeping(): void
    {
        $clean = Describer::clean([
            'id' => 4, 'uuid' => 'x', 'updated_at' => 'now', 'password' => 'hash', 'remember_token' => 't',
            'two_factor_secret' => 's', 'scanner_token' => 'k', 'api_key' => 'a', 'staff_id' => 9, 'created_by' => 3,
            'notes' => 'Prefers lavender', 'nested' => ['otp_code' => '123456', 'price' => 900],
        ]);

        $this->assertSame(['notes' => 'Prefers lavender', 'nested' => ['price' => 900]], $clean);
    }

    public function test_clean_shortens_long_text(): void
    {
        $clean = Describer::clean(['notes' => str_repeat('a', 400)]);

        $this->assertSame(298, mb_strlen($clean['notes']));
    }

    public function test_peso(): void
    {
        $this->assertSame('₱1,250', Describer::peso(1250));
        $this->assertSame('₱99.50', Describer::peso('99.5'));
        $this->assertNull(Describer::peso(0));
        $this->assertNull(Describer::peso('abc'));
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Billing;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ClientPointEntry;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\Payment;
use App\Models\Service;
use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Models\User;
use App\Service\Business\CustomerProgramService;
use App\Service\Business\ProgramRewardService;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// A developer tool: fills one business with customer-program data to test
// with — vouchers, discounts and a membership, eight sample clients with
// points and vouchers, and three membership sales. Everything that has an
// action of its own goes through it (CustomerProgramService::store /
// sellMembership, ProgramRewardService::issueVoucher / redeemPoints), so the
// data is what the pages themselves would have produced.
//
//   php artisan demo:programs owner@example.com            add the samples
//   php artisan demo:programs owner@example.com --dry-run  try it, save nothing
//   php artisan demo:programs owner@example.com --remove   take the sample clients out again
//
// Safe to run twice: programs are matched by name and clients by email, so
// nothing is duplicated. The sample clients' emails end in SAMPLE_DOMAIN,
// which is how --remove finds them. Refuses to run in production.
class SeedProgramSamples extends Command
{
    private const SAMPLE_DOMAIN = '@sample.servora.test';

    protected $signature = 'demo:programs {email : The business owner\'s email} {--remove : Remove the sample clients and what hangs off them} {--dry-run : Do everything, then roll it back}';

    protected $description = 'Fill a business with sample customer-program data (programs, clients with points and vouchers, membership sales) for testing.';

    // The same definitions DemoBusinessSeeder uses, plus one voucher that is
    // for one category of services ("every 5th massage, 50% off a massage").
    // Loyalty is left out on purpose: the owner's own points rules are never
    // overwritten.
    private const PROGRAMS = [
        ['type' => 'voucher', 'name' => 'Welcome Treat', 'values' => ['dealType' => '₱ off', 'value' => '100', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'first_visit']],
        ['type' => 'voucher', 'name' => 'Big Spender', 'values' => ['dealType' => '₱ off', 'value' => '200', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'min_spend', 'value' => 2000]],
        ['type' => 'voucher', 'name' => 'Every 5th Visit', 'values' => ['dealType' => '% off', 'value' => '15', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'visits', 'value' => 5]],
        ['type' => 'voucher', 'name' => 'Points Treat', 'values' => ['dealType' => '% off', 'value' => '10', 'usableOn' => 'All services', 'validDays' => '3 months'], 'condition' => ['kind' => 'points', 'value' => 300]],
        ['type' => 'voucher', 'name' => 'Birthday Massage', 'values' => ['dealType' => '% off', 'value' => '20', 'usableOn' => 'All services', 'validDays' => '1 month'], 'condition' => ['kind' => 'birthday']],
        ['type' => 'voucher', 'name' => 'Monthly Credit', 'values' => ['dealType' => '₱ off', 'value' => '300', 'usableOn' => 'All services', 'validDays' => '1 month'], 'condition' => ['kind' => 'membership_only']],
        ['type' => 'voucher', 'name' => 'Massage Regular', 'values' => ['dealType' => '% off', 'value' => '50', 'validDays' => '3 months', 'usableKind' => 'category', 'usableCategory' => 'Massage', 'earnKind' => 'category', 'earnCategory' => 'Massage'], 'condition' => ['kind' => 'visits', 'value' => 5]],
        ['type' => 'discount', 'name' => 'Senior Citizen / PWD', 'audience' => 'all', 'values' => ['kind' => '%', 'percent' => '20', 'appliesTo' => 'All services', 'eligible' => 'Senior citizens and PWD with a valid ID']],
        ['type' => 'discount', 'name' => 'Member Rate', 'audience' => 'members', 'values' => ['kind' => '%', 'percent' => '10', 'appliesTo' => 'All services', 'eligible' => 'Gold members']],
    ];

    // name, points earned (as [points, days until they expire]), vouchers
    // handed out ([program name, how it was earned]), points voucher bought,
    // and whether they hold the membership (days until it ends, or null).
    private const CLIENTS = [
        ['Andrea', 'Villanueva', [[900, 300], [580, 340]], [['Welcome Treat', 'first_visit'], ['Every 5th Visit', 'visits']], false, 30],
        ['Bianca', 'Soriano', [[980, 280]], [['Big Spender', 'spend']], false, 5],
        ['Carlo', 'Mendoza', [[650, 320]], [['Welcome Treat', 'first_visit']], true, null],
        ['Daniel', 'Reyes', [[200, 12], [250, 310]], [], false, null],
        ['Elaine', 'Cruz', [[120, 350]], [['Welcome Treat', 'first_visit']], false, null],
        ['Francis', 'Lim', [], [['Welcome Treat', 'first_visit']], false, null],
        ['Grace', 'Tan', [[320, 200]], [['Every 5th Visit', 'visits']], false, 30],
        ['Hannah', 'Garcia', [[60, 360]], [], false, null],
    ];

    public function handle(CustomerProgramService $programs, ProgramRewardService $rewards): int
    {
        if (app()->isProduction()) {
            $this->error('This fills a business with made-up data and does not run in production.');

            return self::FAILURE;
        }

        $owner = User::where('email', $this->argument('email'))->where('role', 'business_owner')->first();
        $business = $owner ? SpaBusiness::where('owner_id', $owner->id)->first() : null;
        if (! $business) {
            $this->error('No business owner with that email.');

            return self::FAILURE;
        }

        DB::beginTransaction();
        try {
            $this->option('remove')
                ? $this->remove($business)
                : $this->fill($owner, $business, $programs, $rewards);
            $this->report($business);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Stopped, nothing was saved: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('Dry run: nothing was saved.');
        } else {
            DB::commit();
            $this->info('Saved.');
        }

        return self::SUCCESS;
    }

    private function fill(User $owner, SpaBusiness $business, CustomerProgramService $programs, ProgramRewardService $rewards): void
    {
        $branch = SpaBranch::where('spa_business_id', $business->id)->orderBy('id')->first();
        if (! $branch) {
            throw new \RuntimeException("{$business->business_name} has no branch to put clients at.");
        }
        if (! $rewards->enabledFor($business->id)) {
            throw new \RuntimeException('Customer programs are off for this business (the owner\'s switch, or the plan). Turn them on first.');
        }

        // ── Programs ──
        $has = fn (string $name) => CustomerProgram::where('spa_business_id', $business->id)->where('name', $name)->exists();
        foreach (self::PROGRAMS as $def) {
            // A voucher for one category is left out when the business has
            // no services in it (it couldn't be saved).
            $category = $def['values']['usableCategory'] ?? null;
            if ($category && ! Service::where('spa_business_id', $business->id)->where('category', $category)->exists()) {
                continue;
            }
            if (! $has($def['name'])) {
                $this->ok($programs->store($owner, $def), "program {$def['name']}");
            }
        }
        $program = fn (string $name) => CustomerProgram::where('spa_business_id', $business->id)->where('name', $name)->firstOrFail();
        if (! $has('Gold Membership')) {
            $this->ok($programs->store($owner, [
                'type' => 'membership',
                'name' => 'Gold Membership',
                'values' => ['price' => '999', 'billing' => 'Monthly', 'perks' => 'Priority booking and free welcome tea'],
                'includes' => ['voucher_uuids' => [$program('Monthly Credit')->uuid], 'discount_uuids' => [$program('Member Rate')->uuid]],
            ]), 'program Gold Membership');
        }

        // ── Clients, with their points, vouchers and memberships ──
        foreach (self::CLIENTS as $i => [$first, $last, $earned, $vouchers, $buysWithPoints, $memberDays]) {
            $email = strtolower("{$first}.{$last}") . self::SAMPLE_DOMAIN;
            if (Client::withTrashed()->where('spa_business_id', $business->id)->where('email', $email)->exists()) {
                continue;
            }

            $client = Client::create([
                'spa_business_id' => $business->id,
                'spa_branch_id' => $branch->id,
                'first_name' => $first,
                'last_name' => $last,
                'phone_number' => '0917555' . str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT),
                'email' => $email,
                'is_active' => true,
                'notes' => 'Sample client (php artisan demo:programs).',
            ]);

            // Points: one ledger row per "visit", oldest-expiring first, the
            // same shape ProgramRewardService writes when a bill is paid.
            $total = 0;
            foreach ($earned as [$points, $expiresInDays]) {
                ClientPointEntry::create([
                    'client_id' => $client->id, 'type' => 'earn', 'points' => $points, 'remaining' => $points,
                    'expires_at' => now()->addDays($expiresInDays), 'note' => 'Sample visit',
                ]);
                $total += $points;
            }
            $client->update(['current_points' => $total, 'lifetime_points' => $total]);

            foreach ($vouchers as [$name, $source]) {
                $rewards->issueVoucher($client, $program($name), $source);
            }
            if ($buysWithPoints) {
                $bought = $rewards->redeemPoints($client->fresh(), $program('Points Treat'), $owner, $branch->id);
                if (is_string($bought)) {
                    throw new \RuntimeException("{$first} could not buy Points Treat: {$bought}");
                }
            }

            if ($memberDays !== null) {
                $this->ok($programs->sellMembership($owner, [
                    'branch_uuid' => $branch->uuid,
                    'client_uuid' => $client->uuid,
                    'program_uuid' => $program('Gold Membership')->uuid,
                    'payment_method' => 'Cash',
                    'amount_tendered' => 1000,
                ]), "Gold Membership for {$first}");

                // One member is about to run out, so "ending this week" has a row.
                if ($memberDays < 30) {
                    $membership = ClientMembership::where('client_id', $client->id)->latest('id')->firstOrFail();
                    $membership->update(['starts_at' => now()->subDays(30 - $memberDays), 'ends_at' => now()->addDays($memberDays)]);
                    ClientVoucher::where('client_membership_id', $membership->id)->update(['expires_at' => $membership->ends_at]);
                }
            }
        }
    }

    // The sample clients and everything that hangs off them. The programs
    // stay: the owner manages those from the Programs page.
    private function remove(SpaBusiness $business): void
    {
        $clientIds = Client::withTrashed()->where('spa_business_id', $business->id)
            ->where('email', 'like', '%' . self::SAMPLE_DOMAIN)->pluck('id');
        $billingIds = ClientMembership::whereIn('client_id', $clientIds)->whereNotNull('billing_id')->pluck('billing_id');

        ClientVoucher::whereIn('client_id', $clientIds)->delete();
        ClientMembership::whereIn('client_id', $clientIds)->delete();
        Payment::withTrashed()->whereIn('billing_id', $billingIds)->forceDelete();
        Billing::withTrashed()->whereIn('id', $billingIds)->forceDelete();
        ClientPointEntry::whereIn('client_id', $clientIds)->delete();
        Client::withTrashed()->whereIn('id', $clientIds)->forceDelete();

        $this->line("Removed {$clientIds->count()} sample client(s) and {$billingIds->count()} membership bill(s).");
    }

    // A service call that answered with a refusal stops the run.
    private function ok(mixed $result, string $what): void
    {
        if ($result instanceof JsonResponse && $result->getStatusCode() >= 400) {
            throw new \RuntimeException("{$what}: " . ($result->getData(true)['message'] ?? 'refused'));
        }
    }

    private function report(SpaBusiness $business): void
    {
        $clients = Client::where('spa_business_id', $business->id);
        $samples = (clone $clients)->where('email', 'like', '%' . self::SAMPLE_DOMAIN);
        $ids = (clone $clients)->pluck('id');
        $byType = CustomerProgram::where('spa_business_id', $business->id)->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total', 'type');

        $this->table(['', $business->business_name], [
            ['Programs', collect(['loyalty', 'voucher', 'discount', 'membership'])->map(fn ($t) => ($byType[$t] ?? 0) . " {$t}")->implode(', ')],
            ['Clients', (clone $clients)->count() . ' (' . $samples->count() . ' sample)'],
            ['Points held', number_format((int) (clone $clients)->sum('current_points'))],
            ['Vouchers in wallets', ClientVoucher::whereIn('client_id', $ids)->where('status', 'available')->count()],
            ['Active memberships', ClientMembership::whereIn('client_id', $ids)->where('status', 'active')->where('ends_at', '>', now())->count()],
            ['Membership bills', Billing::where('spa_business_id', $business->id)->where('billing_type', 'Membership')->count()
                . ' (₱' . number_format((float) Billing::where('spa_business_id', $business->id)->where('billing_type', 'Membership')->sum('amount'), 2) . ')'],
        ]);
    }
}

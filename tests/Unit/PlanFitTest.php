<?php

namespace Tests\Unit;

use App\Support\PlanFit;
use PHPUnit\Framework\TestCase;

// Fitting a business into a smaller plan: what needs choosing, and whether a
// choice is valid. No database.
class PlanFitTest extends TestCase
{
    private array $branches = [
        ['id' => 1, 'name' => 'Main', 'upcoming' => 0],
        ['id' => 2, 'name' => 'Acacia', 'upcoming' => 0],
    ];

    private array $accounts = [
        ['id' => 10, 'branch_id' => 1, 'name' => 'Maricel'],
        ['id' => 11, 'branch_id' => 1, 'name' => 'Janine'],
        ['id' => 12, 'branch_id' => 1, 'name' => 'Paolo'],
        ['id' => 13, 'branch_id' => 2, 'name' => 'Ben'],
    ];

    private function problems(array $keepBranches, array $keepAccounts, ?array $branches = null, int $maxBranches = 1, int $maxAccounts = 2): array
    {
        return PlanFit::problems($maxBranches, $maxAccounts, $branches ?? $this->branches, $this->accounts, $keepBranches, $keepAccounts);
    }

    public function test_a_business_inside_the_limits_has_nothing_to_choose(): void
    {
        $this->assertFalse(PlanFit::needsChoice(5, 20, $this->branches, $this->accounts));
        $this->assertFalse(PlanFit::needsChoice(PlanFit::UNLIMITED, PlanFit::UNLIMITED, $this->branches, $this->accounts));
    }

    public function test_too_many_branches_or_logins_needs_a_choice(): void
    {
        $this->assertTrue(PlanFit::needsChoice(1, 20, $this->branches, $this->accounts));
        $this->assertTrue(PlanFit::needsChoice(5, 2, $this->branches, $this->accounts));
    }

    public function test_a_choice_inside_the_limits_is_valid(): void
    {
        $this->assertSame([], $this->problems([1], [10, 11]));
        $this->assertSame([], $this->problems([2], [13]));
        $this->assertSame([], $this->problems([1], []));
    }

    public function test_keeping_too_many_is_refused(): void
    {
        $this->assertStringContainsString('allows 1 branch', $this->problems([1, 2], [10])[0]);
        $this->assertStringContainsString('allows 2 staff logins', $this->problems([1], [10, 11, 12])[0]);
    }

    public function test_something_has_to_be_kept(): void
    {
        $this->assertSame(['Choose the branch to keep.'], $this->problems([], []));
    }

    public function test_someone_elses_branch_or_login_is_refused(): void
    {
        $this->assertCount(1, $this->problems([99], []));
        $this->assertCount(1, $this->problems([1], [999]));
    }

    public function test_a_login_at_a_branch_going_on_hold_cannot_be_kept(): void
    {
        $problems = $this->problems([1], [10, 13]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Ben works only at a branch that is going on hold', $problems[0]);
    }

    public function test_a_login_with_several_branches_is_kept_while_one_of_them_is(): void
    {
        $accounts = [['id' => 20, 'branch_ids' => [1, 2], 'name' => 'Mia'], ['id' => 21, 'branch_ids' => [2, 3], 'name' => 'Leo']];
        $branches = [['id' => 1, 'name' => 'Main', 'upcoming' => 0], ['id' => 2, 'name' => 'Acacia', 'upcoming' => 0], ['id' => 3, 'name' => 'Lanang', 'upcoming' => 0]];

        $this->assertSame([], PlanFit::problems(1, 2, $branches, [$accounts[0]], [1], [20]));
        $problems = PlanFit::problems(1, 2, $branches, $accounts, [1], [20, 21]);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Leo works only at branches that are going on hold', $problems[0]);
    }

    public function test_appointments_at_a_branch_going_on_hold_are_in_the_way(): void
    {
        $branches = [['id' => 1, 'name' => 'Main', 'upcoming' => 4], ['id' => 2, 'name' => 'Acacia', 'upcoming' => 3]];

        // Keeping Main: Acacia's three are in the way; Main's own four are fine.
        $problems = $this->problems([1], [10], $branches);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Acacia still has 3 appointments', $problems[0]);

        $one = [['id' => 1, 'name' => 'Main', 'upcoming' => 0], ['id' => 2, 'name' => 'Acacia', 'upcoming' => 1]];
        $this->assertStringContainsString('1 appointment booked', $this->problems([1], [], $one)[0]);
    }

    public function test_what_goes_on_hold_is_everything_not_kept(): void
    {
        $this->assertSame(
            ['hold_branches' => [2], 'hold_accounts' => [12, 13]],
            PlanFit::outcome($this->branches, $this->accounts, [1], [10, 11]),
        );
    }

    public function test_room_under_a_limit(): void
    {
        $this->assertTrue(PlanFit::hasRoom(1, 0));
        $this->assertFalse(PlanFit::hasRoom(1, 1));
        $this->assertTrue(PlanFit::hasRoom(PlanFit::UNLIMITED, 500));
        $this->assertFalse(PlanFit::hasRoom(5, 4, 2));
    }
}

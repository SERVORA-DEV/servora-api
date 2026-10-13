<?php

namespace App\Service\Business;

use App\Models\AppointmentServiceItem;
use App\Models\Attendance;
use App\Models\ClientMembership;
use App\Models\ClientVoucher;
use App\Models\CustomerProgram;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Gives every record that already exists its own copy of what it was
// (App\Support\History), once, when the history columns are added — from the
// menu, staff list, rooms, programs and settings as they stand that day,
// which is the only record there is. Only fills what is empty, so it is safe
// to run again.
//
// Run by the migration 2026_10_12_090000_keep_history_as_it_happened.
class HistoryBackfill
{
    public function __construct(
        private CommissionCalculator $commission,
        private AttendanceStatusCalculator $attendance,
    ) {
    }

    /** @return array<string, int> how many rows each part filled */
    public function run(): array
    {
        return [
            'services' => $this->services(),
            'packages' => $this->packages(),
            'therapists_and_rooms' => $this->therapistsAndRooms(),
            'payments' => $this->payments(),
            'commission' => $this->commission(),
            'attendance' => $this->attendance(),
            'vouchers' => $this->vouchers(),
            'memberships' => $this->memberships(),
        ];
    }

    // Straight from the tables, so lengths and services that have since been
    // removed (soft-deleted) are still found.
    private function services(): int
    {
        $variant = 'FROM service_variants v WHERE v.id = appointment_services.service_variant_id';
        $service = 'FROM service_variants v JOIN services sv ON sv.id = v.service_id WHERE v.id = appointment_services.service_variant_id';

        return DB::update("UPDATE appointment_services SET
            service_name = (SELECT sv.name {$service}),
            duration_minutes = (SELECT v.duration_minutes {$variant}),
            service_type = (SELECT sv.service_type {$service}),
            service_category = (SELECT sv.category {$service})
            WHERE service_name IS NULL");
    }

    private function packages(): int
    {
        return DB::update('UPDATE appointment_packages SET
            package_name = (SELECT p.name FROM packages p WHERE p.id = appointment_packages.package_id)
            WHERE package_name IS NULL');
    }

    private function therapistsAndRooms(): int
    {
        $staff = DB::update("UPDATE therapist_assignments SET
            staff_name = (SELECT NULLIF(TRIM(COALESCE(st.first_name, '') || ' ' || COALESCE(st.last_name, '')), '') FROM staff st WHERE st.id = therapist_assignments.staff_id)
            WHERE staff_id IS NOT NULL AND staff_name IS NULL");
        $rooms = DB::update('UPDATE therapist_assignments SET
            facility_name = (SELECT f.name FROM facilities f WHERE f.id = therapist_assignments.facility_id)
            WHERE facility_id IS NOT NULL AND facility_name IS NULL');

        return $staff + $rooms;
    }

    private function payments(): int
    {
        $filled = 0;
        $receivers = DB::table('payments')->whereNotNull('received_by')->whereNull('received_by_name')->distinct()->pluck('received_by');
        foreach (User::with('staff')->whereIn('id', $receivers)->get() as $user) {
            $filled += DB::table('payments')->where('received_by', $user->id)->whereNull('received_by_name')
                ->update(['received_by_name' => Payment::nameOf($user)]);
        }

        return $filled;
    }

    // What each completed service earned — under today's Staff Policies.
    private function commission(): int
    {
        $filled = 0;
        AppointmentServiceItem::whereHas('therapistAssignments', fn ($q) => $q
            ->where('assignment_status', 'Completed')->whereNotNull('staff_id')->whereNull('commission_amount'))
            ->with('appointment.branch.business.settings')
            ->chunkById(200, function ($lines) use (&$filled) {
                foreach ($lines as $line) {
                    $filled += $this->commission->freeze($line, onlyMissing: true);
                }
            });

        return $filled;
    }

    // How each recorded day turned out — under today's schedules and settings.
    private function attendance(): int
    {
        $filled = 0;
        Attendance::whereNull('resolved_status')->chunkById(200, function ($rows) use (&$filled) {
            foreach ($rows as $row) {
                $this->attendance->keep($row, force: true);
                if ($row->isDirty()) {
                    // Quietly: this is not somebody editing the day.
                    $row->timestamps = false;
                    $row->saveQuietly();
                    $filled++;
                }
            }
        });

        return $filled;
    }

    private function vouchers(): int
    {
        $filled = 0;
        $ids = ClientVoucher::whereNull('program_name')->distinct()->pluck('customer_program_id');
        foreach (CustomerProgram::withTrashed()->whereIn('id', $ids)->get() as $program) {
            $filled += DB::table('client_vouchers')->where('customer_program_id', $program->id)->whereNull('program_name')
                ->update(['program_name' => $program->name, 'deal' => json_encode($program->values)]);
        }

        return $filled;
    }

    private function memberships(): int
    {
        $filled = 0;
        $ids = ClientMembership::whereNull('program_name')->distinct()->pluck('customer_program_id');
        foreach (CustomerProgram::withTrashed()->with('items')->whereIn('id', $ids)->get() as $program) {
            $filled += DB::table('client_memberships')->where('customer_program_id', $program->id)->whereNull('program_name')
                ->update(['program_name' => $program->name, 'perks' => json_encode(ClientMembership::perksOf($program))]);
        }

        return $filled;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Billing;
use App\Models\SpaBusinessSetting;
use App\Repository\PaymentRepository;
use App\Service\Business\ClientNotifier;
use App\Service\Business\StaffNotifier;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'billings:send-payment-reminders';

    protected $description = "Remind clients (and tell the owner and manager) about appointment bills still unpaid a day after they were issued.";

    // A bill settled at the counter is paid within minutes; one still open a
    // day later was genuinely left unpaid.
    private const GRACE_HOURS = 24;

    public function handle(ClientNotifier $clients, StaffNotifier $staff, PaymentRepository $payments): int
    {
        $sent = 0;

        Billing::query()
            ->where('billing_type', 'Appointment')
            ->where('status', 'Pending')
            ->whereNotNull('appointment_id')
            ->whereNull('payment_reminder_sent_at')
            ->where(fn ($q) => $q->where('due_at', '<=', now())
                ->orWhere(fn ($q) => $q->whereNull('due_at')->where('issued_at', '<=', now()->subHours(self::GRACE_HOURS))))
            ->with('appointment.client', 'appointment.branch.business.settings')
            ->chunkById(200, function ($billings) use ($clients, $staff, $payments, &$sent) {
                foreach ($billings as $billing) {
                    $sent += $this->remind($billing, $clients, $staff, $payments) ? 1 : 0;
                }
            });

        $this->info("Sent {$sent} payment reminder(s).");

        return self::SUCCESS;
    }

    private function remind(Billing $billing, ClientNotifier $clients, StaffNotifier $staff, PaymentRepository $payments): bool
    {
        $appointment = $billing->appointment;
        $settings = $appointment?->branch?->business?->settings?->section('notifications')
            ?? SpaBusinessSetting::DEFAULTS['notifications'];

        $billing->forceFill(['payment_reminder_sent_at' => now()])->saveQuietly();

        if (! $appointment || ! $settings['send_payment_reminder'] || ! $settings['in_app_channel']) {
            return false;
        }

        $due = max(0, (float) $billing->amount - $payments->paidTotalForBilling($billing->id));
        if ($due <= 0) {
            return false;
        }
        $amount = '₱' . number_format($due, 2);

        $clients->appointment(
            $appointment,
            'Payment pending',
            "You have {$amount} left to pay for booking {$appointment->appointment_number} at {$appointment->branch?->branch_name}.",
            'Payment',
        );
        $staff->appointment(
            $appointment,
            'Unpaid bill',
            "{$amount} is still unpaid for booking {$appointment->appointment_number} (bill {$billing->billing_number}).",
            'send_payment_reminder',
            'Payment',
            false,
        );

        return true;
    }
}

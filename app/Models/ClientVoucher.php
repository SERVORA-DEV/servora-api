<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A voucher in a client's wallet — earned (spend / visits / first visit /
// birthday), bought with points, or bundled in a membership.
class ClientVoucher extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'client_id',
        'customer_program_id',
        // The deal it was issued with — its own copy of the program's name
        // and values (App\Support\History): editing or removing the program
        // afterwards changes vouchers issued from then on, not this one.
        'program_name',
        'deal',
        'source',
        'source_billing_id',
        'client_membership_id',
        'issued_at',
        'expires_at',
        'status',
        'used_at',
        'used_billing_id',
        'used_by',
    ];

    protected function casts(): array
    {
        return [
            'deal' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function uniqueIds()
    {
        return ['uuid'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function program()
    {
        return $this->belongsTo(CustomerProgram::class, 'customer_program_id')->withTrashed();
    }

    protected static function booted(): void
    {
        static::saving(function (self $voucher) {
            if (\App\Support\History::ready() && (! $voucher->exists || $voucher->isDirty('customer_program_id') || $voucher->program_name === null)) {
                $program = CustomerProgram::withTrashed()->find($voucher->customer_program_id);
                $voucher->program_name = $program?->name;
                $voucher->deal = $program?->values;
            }
        });
    }

    /**
     * The program as this voucher was issued: its name and deal are the
     * voucher's own copy (the live program only for a voucher with none).
     * A stand-in for reading and working out the discount — never saved.
     */
    public function dealProgram(): ?CustomerProgram
    {
        $live = $this->program;
        if (! $live) {
            return null;
        }
        if ($this->program_name === null && ! is_array($this->deal)) {
            return $live;
        }

        $held = clone $live;
        $held->name = \App\Support\History::own($this->program_name, $live->name);
        if (is_array($this->deal)) {
            $held->values = $this->deal;
        }

        return $held;
    }

    /**
     * Which of these vouchers are held by a booking that hasn't used them yet:
     * a client booked with it in the app and the visit is still ahead (or done
     * and not billed). $exceptAppointmentId is the booking asking, whose own
     * hold doesn't count against it.
     *
     * @param  iterable<int>  $ids
     * @return int[]
     */
    public static function heldIds(iterable $ids, ?int $exceptAppointmentId = null): array
    {
        $ids = collect($ids)->all();
        if (! $ids || ! Appointment::holdsVouchers()) {
            return [];
        }

        return Appointment::whereIn('client_voucher_id', $ids)
            ->when($exceptAppointmentId, fn ($q) => $q->where('id', '!=', $exceptAppointmentId))
            ->where(fn ($q) => $q
                ->whereIn('status', [Appointment::STATUS_SCHEDULED, Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_SERVICE])
                ->orWhere(fn ($done) => $done->where('status', Appointment::STATUS_COMPLETED)->whereDoesntHave('billing')))
            ->pluck('client_voucher_id')->unique()->values()->all();
    }

    public function isUsable(): bool
    {
        return $this->status === 'available' && (! $this->expires_at || $this->expires_at->isFuture());
    }
}

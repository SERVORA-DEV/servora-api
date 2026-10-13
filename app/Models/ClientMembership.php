<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// A membership sold to a client at the front desk. Lasts one billing period
// (starts_at → ends_at); renewing starts a new row.
class ClientMembership extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'client_id',
        'customer_program_id',
        // What it was sold as — its own copy of the program's name and of
        // the vouchers and discounts it included (App\Support\History).
        'program_name',
        'perks',
        'spa_branch_id',
        'billing_id',
        'starts_at',
        'ends_at',
        'status',
        'price',
        'sold_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'perks' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'price' => 'decimal:2',
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

    public function branch()
    {
        return $this->belongsTo(SpaBranch::class, 'spa_branch_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $membership) {
            if (\App\Support\History::ready() && (! $membership->exists || $membership->isDirty('customer_program_id') || $membership->program_name === null)) {
                $program = CustomerProgram::withTrashed()->with('items')->find($membership->customer_program_id);
                $membership->program_name = $program?->name;
                $membership->perks = $program ? self::perksOf($program) : [];
            }
        });
    }

    /** A membership program's bundle, as a plain list that can be kept. */
    public static function perksOf(CustomerProgram $program): array
    {
        return $program->items->map(fn (CustomerProgram $item) => [
            'id' => $item->id,
            'uuid' => $item->uuid,
            'type' => $item->type,
            'name' => $item->name,
            'audience' => $item->audience,
            'is_active' => (bool) $item->is_active,
            'values' => $item->values,
        ])->values()->all();
    }

    /** The membership's name when it was sold — its own copy first. */
    public function displayName(): ?string
    {
        return \App\Support\History::own($this->program_name, fn () => $this->program?->name);
    }

    /** The programs (vouchers, discounts) this membership includes: as sold. */
    public function perkIds(): array
    {
        return is_array($this->perks)
            ? array_map('intval', array_column($this->perks, 'id'))
            : ($this->program?->items->pluck('id')->all() ?? []);
    }

    /**
     * A program as this membership was sold with it: when it is one of the
     * membership's perks, its name and deal are the kept copy. A stand-in for
     * reading and working out the discount — never saved.
     */
    public function held(CustomerProgram $program): CustomerProgram
    {
        $kept = collect(is_array($this->perks) ? $this->perks : [])->firstWhere('id', $program->id);
        if (! $kept) {
            return $program;
        }

        $held = clone $program;
        $held->name = \App\Support\History::own($kept['name'] ?? null, $program->name);
        if (is_array($kept['values'] ?? null)) {
            $held->values = $kept['values'];
        }

        return $held;
    }

    /** The discounts it was sold with — still the member's, even if since switched off or removed. */
    public function heldDiscounts(): \Illuminate\Support\Collection
    {
        $ids = collect(is_array($this->perks) ? $this->perks : [])->where('type', 'discount')->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        return CustomerProgram::withTrashed()->whereIn('id', $ids)->get()->map(fn (CustomerProgram $p) => $this->held($p))->values();
    }

    public function isCurrent(): bool
    {
        return $this->status === 'active' && $this->ends_at->isFuture();
    }
}

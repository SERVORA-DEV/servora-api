<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The customer now picks their payment channel on Xendit's hosted
    // invoice page instead of us pre-selecting GCash/Maya, so payments can
    // come back through any channel Xendit reports (cards, online
    // banking/direct debit, QR, other e-wallets) — the enum needs room for
    // those beyond the original GCash/Maya/Bank Transfer/Cards set.
    private array $newValues = [
        'Cash',
        'GCash',
        'Maya',
        'Bank Transfer',
        'Online Banking',
        'Credit Card',
        'Debit Card',
        'QR Code',
        'Other',
    ];

    private array $oldValues = [
        'Cash',
        'GCash',
        'Maya',
        'Bank Transfer',
        'Credit Card',
        'Debit Card',
    ];

    public function up(): void
    {
        $values = implode(',', array_map(fn ($v) => "'{$v}'", $this->newValues));
        DB::statement("ALTER TABLE payments MODIFY payment_method ENUM({$values}) NULL");
    }

    public function down(): void
    {
        $values = implode(',', array_map(fn ($v) => "'{$v}'", $this->oldValues));
        DB::statement("ALTER TABLE payments MODIFY payment_method ENUM({$values}) NULL");
    }
};

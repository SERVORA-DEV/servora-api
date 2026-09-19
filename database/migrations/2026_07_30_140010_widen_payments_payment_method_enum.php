<?php

use App\Support\PostgresSchema;
use Illuminate\Database\Migrations\Migration;

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
        PostgresSchema::redefineEnum('payments', 'payment_method', $this->newValues, nullable: true);
    }

    public function down(): void
    {
        PostgresSchema::redefineEnum('payments', 'payment_method', $this->oldValues, nullable: true);
    }
};

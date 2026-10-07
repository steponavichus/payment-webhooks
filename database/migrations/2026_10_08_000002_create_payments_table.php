<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 50);
            $table->string('payment_id', 255);
            // Amounts are stored in minor units (cents) to avoid float errors.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['provider', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

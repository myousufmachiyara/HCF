<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psx_brokers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->string('client_code', 50)->nullable();      // trading account / UIN at the broker
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();

            // Commission settings (were on the Excel "Settings" sheet)
            $table->decimal('min_commission_per_share', 10, 4)->default(0.05);  // Rs per share
            $table->decimal('commission_rate', 8, 4)->default(0.15);            // % of trade value
            $table->boolean('intraday_buy_waiver')->default(true);              // no buy commission on same-day sell

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psx_brokers');
    }
};

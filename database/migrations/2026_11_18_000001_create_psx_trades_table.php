<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trade header: one contract note / entry (date + broker), many rows
        Schema::create('psx_trades', function (Blueprint $table) {
            $table->id();
            $table->string('trade_no', 20)->unique();
            $table->date('trade_date')->index();
            $table->foreignId('psx_broker_id')->constrained('psx_brokers');
            $table->string('contract_note_no', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('psx_trade_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('psx_trade_id')->constrained('psx_trades')->cascadeOnDelete();
            $table->enum('type', ['buy', 'sell', 'opening']);
            $table->foreignId('psx_stock_id')->constrained('psx_stocks');
            $table->decimal('qty', 15, 2);
            $table->decimal('price', 15, 4);
            $table->decimal('value', 15, 2);          // qty x price
            $table->decimal('commission', 15, 2);     // at entry, from broker settings (before intraday waiver)
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);     // buy: value + charges, sell: value - charges
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->index(['psx_stock_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psx_trade_items');
        Schema::dropIfExists('psx_trades');
    }
};

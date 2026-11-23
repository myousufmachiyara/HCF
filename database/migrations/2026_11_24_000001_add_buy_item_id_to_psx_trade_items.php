<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A sale can name the purchase it closes ("sold from"), as each row of the client's Excel does.
        // Empty = automatic (same-day buys first, then oldest).
        Schema::table('psx_trade_items', function (Blueprint $table) {
            $table->foreignId('buy_item_id')->nullable()->after('psx_stock_id')
                ->constrained('psx_trade_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('psx_trade_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('buy_item_id');
        });
    }
};

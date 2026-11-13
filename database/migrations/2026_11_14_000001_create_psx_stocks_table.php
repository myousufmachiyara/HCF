<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psx_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->index();      // e.g. TRG, PRL (unique among non-deleted rows, checked in controller)
            $table->string('name')->nullable();         // company name
            $table->string('sector')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psx_stocks');
    }
};

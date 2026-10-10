<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20);
            // Desnormalizados para evitar MIN/MAX sobre pedidos em consultas de recência
            $table->dateTime('first_order_at')->nullable();
            $table->dateTime('last_order_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'last_order_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

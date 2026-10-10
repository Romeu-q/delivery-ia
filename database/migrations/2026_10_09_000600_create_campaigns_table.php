<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->json('audience_rule');
            $table->unsignedInteger('audience_count');
            $table->string('message_title');
            $table->text('message_body');
            // Nunca é enviada automaticamente: a IA só cria rascunhos
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });

        Schema::create('campaign_customer', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->primary(['campaign_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_customer');
        Schema::dropIfExists('campaigns');
    }
};

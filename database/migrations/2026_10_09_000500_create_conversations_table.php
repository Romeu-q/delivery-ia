<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Identifica o visitante anônimo (sessão) dono da conversa
            $table->string('visitor_id', 64);
            $table->string('title');
            $table->timestamps();

            $table->index(['visitor_id', 'updated_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // user | assistant
            $table->text('content');
            // Ferramentas usadas, gráficos, verificação dos números, campanha criada...
            $table->json('meta')->nullable();
            $table->string('status', 20)->default('ok'); // ok | error
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('type', 32);
            $table->enum('direction', ['in', 'out']);
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('NGN');
            $table->string('status', 20)->default('successful');
            $table->decimal('fee', 10, 2)->nullable()->default(0.00);
            $table->text('description')->nullable();
            $table->string('counterparty')->nullable();
            $table->string('counterparty_bank')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

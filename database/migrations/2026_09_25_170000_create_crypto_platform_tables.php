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
        // 1. Add auto_convert_crypto to users table
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('auto_convert_crypto')->default(false)->after('notifications_read_at');
        });

        // 2. Crypto balances per user
        Schema::create('crypto_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 10); // USDT, BTC, ETH, BNB, SOL
            $table->decimal('balance', 20, 8)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'symbol']);
        });

        // 3. User deposit wallet addresses
        Schema::create('crypto_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 10); // USDT, BTC, ETH
            $table->string('network', 20); // TRC20, ERC20, BEP20, BTC, SOL
            $table->string('address');
            $table->string('memo')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'symbol', 'network']);
        });

        // 4. Crypto deposit history
        Schema::create('crypto_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tx_hash')->unique();
            $table->string('symbol', 10);
            $table->string('network', 20);
            $table->decimal('amount', 20, 8);
            $table->string('status', 20)->default('confirmed'); // pending, confirmed, failed
            $table->boolean('converted_to_naira')->default(false);
            $table->decimal('naira_amount', 14, 2)->nullable();
            $table->decimal('rate', 14, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_deposits');
        Schema::dropIfExists('crypto_addresses');
        Schema::dropIfExists('crypto_wallets');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_convert_crypto');
        });
    }
};

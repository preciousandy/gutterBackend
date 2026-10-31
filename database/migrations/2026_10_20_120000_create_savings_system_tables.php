<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('savings_plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category')->default('gvault');
            $table->string('tagline')->nullable();
            $table->decimal('annual_rate', 5, 2)->default(15.00); // e.g. 15.00%
            $table->string('badge')->nullable();
            $table->string('badge_bg')->nullable();
            $table->string('badge_text')->nullable();
            $table->string('icon')->default('wallet-outline');
            $table->string('theme_color')->default('#059669');
            $table->string('theme_bg')->default('#F0FDF4');
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->decimal('min_deposit', 15, 2)->default(500.00);
            $table->string('lock_period')->default('None (Instant)');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('allow_withdrawals')->default(true);
            $table->unsignedInteger('order_index')->default(0);
            $table->timestamps();
        });

        Schema::create('user_savings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('plan_id')->constrained('savings_plans')->onDelete('cascade');
            $table->string('title');
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->decimal('target_amount', 15, 2)->nullable();
            $table->decimal('annual_rate', 5, 2)->default(15.00);
            $table->decimal('total_interest_earned', 15, 2)->default(0.00);
            $table->timestamp('last_interest_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->string('status')->default('active'); // active, completed, closed
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('user_saving_id')->constrained('user_savings')->onDelete('cascade');
            $table->string('type'); // deposit, withdrawal, interest
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('reference')->unique();
            $table->string('description')->nullable();
            $table->string('status')->default('successful'); // successful, pending, failed
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Seed the 4 official Gutter Savings Plans
        DB::table('savings_plans')->insert([
            [
                'key' => 'gvault',
                'name' => 'G-Vault',
                'category' => 'gvault',
                'tagline' => 'Daily interest with free anytime withdrawal',
                'annual_rate' => 15.00,
                'badge' => 'DAILY PAYOUT',
                'badge_bg' => '#ECFDF5',
                'badge_text' => '#059669',
                'icon' => 'wallet-outline',
                'theme_color' => '#059669',
                'theme_bg' => '#F0FDF4',
                'description' => 'Earn high daily compounding interest on your spare balance in your G-Vault. Deposit anytime and withdraw whenever you want with zero penalty fees.',
                'features' => json_encode(['Daily interest paid at 6:00 AM', 'Withdraw 100% anytime free', 'Automated compounding']),
                'min_deposit' => 500.00,
                'lock_period' => 'None (Instant)',
                'is_enabled' => true,
                'allow_withdrawals' => true,
                'order_index' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'target',
                'name' => 'Target Savings',
                'category' => 'target',
                'tagline' => 'Set financial goals and hit your targets',
                'annual_rate' => 16.50,
                'badge' => 'POPULAR GOAL',
                'badge_bg' => '#EDE9FE',
                'badge_text' => '#6D28D9',
                'icon' => 'flag-outline',
                'theme_color' => '#7C3AED',
                'theme_bg' => '#F5F3FF',
                'description' => 'Save toward specific personal milestones like Rent, Business, Vacation, or Tuition. Automate daily, weekly, or monthly savings.',
                'features' => json_encode(['Flexible target amounts & dates', 'Automated recurring stashing', 'Goal milestone celebrations']),
                'min_deposit' => 1000.00,
                'lock_period' => 'Flexible Goal End Date',
                'is_enabled' => true,
                'allow_withdrawals' => true,
                'order_index' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'fixed',
                'name' => 'SafeBox (Fixed)',
                'category' => 'fixed',
                'tagline' => 'Lock funds for maximum guaranteed returns',
                'annual_rate' => 20.00,
                'badge' => 'HIGHEST YIELD',
                'badge_bg' => '#FEF3C7',
                'badge_text' => '#B45309',
                'icon' => 'lock-closed-outline',
                'theme_color' => '#D97706',
                'theme_bg' => '#FFFBEB',
                'description' => 'Lock away disciplined lump sums for 30, 60, 90, 180, or 365 days. Protect your capital from impulsive spending while locking in top yield.',
                'features' => json_encode(['Guaranteed up to 20% annual yield', 'Choice of 30 to 365 days lock', 'Interest paid upfront or maturity']),
                'min_deposit' => 5000.00,
                'lock_period' => '30 - 365 Days',
                'is_enabled' => true,
                'allow_withdrawals' => true,
                'order_index' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'spend_save',
                'name' => 'Spend & Save',
                'category' => 'spend_save',
                'tagline' => 'Automate micro-savings every time you spend',
                'annual_rate' => 15.00,
                'badge' => 'EFFORTLESS',
                'badge_bg' => '#E0F2FE',
                'badge_text' => '#0369A1',
                'icon' => 'flash-outline',
                'theme_color' => '#0284C7',
                'theme_bg' => '#F0F9FF',
                'description' => 'Never worry about saving manually. Whenever you pay a bill, buy airtime, or transfer money, a percentage is automatically stashed into high-yield savings.',
                'features' => json_encode(['Save 5% to 20% on each transaction', 'Interest compounds automatically', 'Turn on/off anytime with 1 tap']),
                'min_deposit' => 100.00,
                'lock_period' => 'Instant Access',
                'is_enabled' => true,
                'allow_withdrawals' => true,
                'order_index' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
        Schema::dropIfExists('user_savings');
        Schema::dropIfExists('savings_plans');
    }
};

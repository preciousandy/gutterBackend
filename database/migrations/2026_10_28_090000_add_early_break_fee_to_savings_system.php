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
        if (Schema::hasTable('savings_plans') && !Schema::hasColumn('savings_plans', 'early_break_fee_percentage')) {
            Schema::table('savings_plans', function (Blueprint $table) {
                $table->decimal('early_break_fee_percentage', 5, 2)->default(2.50)->after('annual_rate');
            });

            // Set default fees for existing plans
            DB::table('savings_plans')->whereIn('key', ['fixed', 'target'])->update(['early_break_fee_percentage' => 2.50]);
            DB::table('savings_plans')->whereIn('key', ['gvault', 'spend_save'])->update(['early_break_fee_percentage' => 0.00]);
        }

        if (Schema::hasTable('savings_transactions') && !Schema::hasColumn('savings_transactions', 'fee')) {
            Schema::table('savings_transactions', function (Blueprint $table) {
                $table->decimal('fee', 15, 2)->default(0.00)->after('amount');
                $table->decimal('forfeited_interest', 15, 2)->default(0.00)->after('fee');
                $table->decimal('principal', 15, 2)->nullable()->after('forfeited_interest');
                $table->decimal('net_payout', 15, 2)->nullable()->after('principal');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('savings_plans') && Schema::hasColumn('savings_plans', 'early_break_fee_percentage')) {
            Schema::table('savings_plans', function (Blueprint $table) {
                $table->dropColumn('early_break_fee_percentage');
            });
        }

        if (Schema::hasTable('savings_transactions')) {
            Schema::table('savings_transactions', function (Blueprint $table) {
                $columns = ['fee', 'forfeited_interest', 'principal', 'net_payout'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('savings_transactions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};

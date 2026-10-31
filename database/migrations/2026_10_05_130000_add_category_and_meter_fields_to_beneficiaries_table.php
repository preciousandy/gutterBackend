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
        Schema::table('beneficiaries', function (Blueprint $table) {
            if (!Schema::hasColumn('beneficiaries', 'category')) {
                $table->string('category', 32)->default('transfer')->after('user_id');
            }
            if (!Schema::hasColumn('beneficiaries', 'nickname')) {
                $table->string('nickname', 100)->nullable()->after('name');
            }
            if (!Schema::hasColumn('beneficiaries', 'meter_type')) {
                $table->string('meter_type', 20)->nullable()->after('account_number');
            }

            $table->index(['user_id', 'category', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'category', 'created_at']);
            $table->dropColumn(['category', 'nickname', 'meter_type']);
        });
    }
};

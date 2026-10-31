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
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable()->unique()->after('email');
            $table->unsignedTinyInteger('tier')->default(1)->after('password');
            $table->string('kyc_status')->default('unverified')->after('tier');
            $table->boolean('has_pin')->default(false)->after('kyc_status');
            $table->string('pin')->nullable()->after('has_pin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'phone',
                'tier',
                'kyc_status',
                'has_pin',
                'pin',
            ]);
        });
    }
};

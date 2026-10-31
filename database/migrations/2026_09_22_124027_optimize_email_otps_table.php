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
        Schema::table('email_otps', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('code_hash');
            $table->index(['user_id', 'verified_at', 'expires_at'], 'idx_user_verified_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_otps', function (Blueprint $table) {
            $table->dropIndex('idx_user_verified_expires');
            $table->dropColumn('attempts');
        });
    }
};

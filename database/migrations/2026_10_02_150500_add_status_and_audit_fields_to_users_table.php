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
            if (!Schema::hasColumn('users', 'status')) {
                $table->enum('status', ['active', 'frozen', 'suspended', 'blocked'])
                    ->default('active')
                    ->after('password');
            }
            if (!Schema::hasColumn('users', 'status_reason')) {
                $table->string('status_reason')->nullable()->after('status');
            }
            if (!Schema::hasColumn('users', 'status_updated_by')) {
                $table->string('status_updated_by')->nullable()->after('status_reason');
            }
            if (!Schema::hasColumn('users', 'status_updated_at')) {
                $table->timestamp('status_updated_at')->nullable()->after('status_updated_by');
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('status_updated_at');
            }
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            // Indexes for lightning-fast queries
            $table->index('status');
            $table->index('last_login_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['last_login_at']);
            $table->dropColumn([
                'status',
                'status_reason',
                'status_updated_by',
                'status_updated_at',
                'last_login_at',
                'deleted_at',
            ]);
        });
    }
};

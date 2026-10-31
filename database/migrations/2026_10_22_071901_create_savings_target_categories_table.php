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
        Schema::create('savings_target_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon')->default('flag-outline');
            $table->string('color')->default('#7C3AED');
            $table->string('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('order_index')->default(0);
            $table->timestamps();
        });

        Schema::table('user_savings', function (Blueprint $table) {
            $table->foreignId('target_category_id')
                ->nullable()
                ->after('plan_id')
                ->constrained('savings_target_categories')
                ->onDelete('set null');
        });

        // Seed default popular target categories
        DB::table('savings_target_categories')->insert([
            [
                'name' => 'Rent & Accommodation',
                'icon' => 'home-outline',
                'color' => '#7C3AED',
                'description' => 'Save towards yearly house rent or apartment deposit',
                'is_enabled' => true,
                'order_index' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Education & Tuition',
                'icon' => 'school-outline',
                'color' => '#059669',
                'description' => 'Tuition fees, textbooks, school gear & certifications',
                'is_enabled' => true,
                'order_index' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Business & Startup',
                'icon' => 'briefcase-outline',
                'color' => '#D97706',
                'description' => 'Seed capital, shop inventory & business expansion',
                'is_enabled' => true,
                'order_index' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Vacation & Travel',
                'icon' => 'airplane-outline',
                'color' => '#0284C7',
                'description' => 'Holiday trips, flight tickets, hotel & adventures',
                'is_enabled' => true,
                'order_index' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Vehicle & Mobility',
                'icon' => 'car-sport-outline',
                'color' => '#DC2626',
                'description' => 'Buy a new car, motorcycle or vehicle maintenance',
                'is_enabled' => true,
                'order_index' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Gadgets & Tech',
                'icon' => 'phone-portrait-outline',
                'color' => '#8B5CF6',
                'description' => 'Smartphones, laptops, iPads & workstations',
                'is_enabled' => true,
                'order_index' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Emergency Fund',
                'icon' => 'shield-checkmark-outline',
                'color' => '#10B981',
                'description' => 'Dedicated rainy-day financial reserve buffer',
                'is_enabled' => true,
                'order_index' => 7,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Wedding & Celebration',
                'icon' => 'heart-outline',
                'color' => '#EC4899',
                'description' => 'Weddings, birthdays, family events & milestones',
                'is_enabled' => true,
                'order_index' => 8,
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
        Schema::table('user_savings', function (Blueprint $table) {
            $table->dropForeign(['target_category_id']);
            $table->dropColumn('target_category_id');
        });

        Schema::dropIfExists('savings_target_categories');
    }
};

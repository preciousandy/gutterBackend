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
        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 32); // nin, bvn, pvc, passport
            $table->text('document_number'); // Encrypted at rest (AES-256-CBC)
            $table->string('status', 32)->default('pending'); // pending, id_verified, verified, rejected
            $table->string('provider', 32)->default('mock'); // mock, prembly, smile_id
            $table->json('id_data')->nullable(); // legal name, dob, gender, photo_url returned
            $table->json('face_data')->nullable(); // liveness score, match confidence
            $table->string('rejection_reason')->nullable();
            $table->timestamp('id_verified_at')->nullable();
            $table->timestamp('face_verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_verifications');
    }
};

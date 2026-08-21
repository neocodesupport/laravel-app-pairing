<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pairing_pairings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('pairing_uuid')->unique();
            $table->ulid('local_application_id');
            $table->ulid('remote_application_id')->nullable();
            $table->string('remote_name')->nullable();
            $table->string('remote_base_url')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_communication_at')->nullable();
            $table->timestamps();

            $table->foreign('local_application_id')->references('id')->on('pairing_applications')->cascadeOnDelete();
            $table->foreign('remote_application_id')->references('id')->on('pairing_applications')->nullOnDelete();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_pairings');
    }
};

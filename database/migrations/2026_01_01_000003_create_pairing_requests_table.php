<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pairing_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('request_id')->unique();
            $table->string('direction');
            $table->ulid('pairing_id')->nullable();
            $table->string('remote_app_id')->nullable();
            $table->string('remote_name')->nullable();
            $table->string('remote_base_url')->nullable();
            $table->string('remote_environment')->nullable();
            $table->string('remote_api_version')->nullable();
            $table->text('remote_public_key')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('pairing_id')->references('id')->on('pairing_pairings')->nullOnDelete();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_requests');
    }
};

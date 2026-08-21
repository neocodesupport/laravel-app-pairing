<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pairing_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('pairing_id');
            $table->string('owner');
            $table->text('public_key');
            $table->text('private_key')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('pairing_id')->references('id')->on('pairing_pairings')->cascadeOnDelete();
            $table->index(['pairing_id', 'owner', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_keys');
    }
};

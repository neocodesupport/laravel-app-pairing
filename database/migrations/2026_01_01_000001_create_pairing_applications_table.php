<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pairing_applications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('app_id')->unique();
            $table->string('name');
            $table->string('base_url');
            $table->string('environment');
            $table->string('api_version');
            $table->boolean('is_local')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_applications');
    }
};

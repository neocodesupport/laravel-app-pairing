<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regression coverage for the ULID-truncation bug this migration exists to
 * prevent: spatie/laravel-activitylog's own stock migration types
 * `subject_id` as an unsigned bigint, which silently truncates this
 * package's ULID primary keys on strict database engines. These tests
 * simulate a host application that already manages its own copy of
 * spatie's stock migration (published independently of this package,
 * before or after installing it), and assert this package's migration
 * never collides with it and always leaves `subject_id` ULID-safe.
 */
function runActivityLogMigration(): void
{
    $migration = require __DIR__.'/../../../database/migrations/9999_12_31_235959_create_or_fix_activity_log_table.php';

    $migration->up();
}

function createStockSpatieActivityLogTable(): void
{
    Schema::create('activity_log', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->nullableMorphs('subject', 'subject');
        $table->nullableMorphs('causer', 'causer');
        $table->json('properties')->nullable();
        $table->timestamps();
        $table->index('log_name');
    });
}

beforeEach(function () {
    Schema::dropIfExists('activity_log');
});

it('creates the table with a ulid-compatible subject_id when nothing exists yet', function () {
    runActivityLogMigration();

    expect(Schema::hasTable('activity_log'))->toBeTrue();

    $type = Schema::getColumnType('activity_log', 'subject_id');

    expect($type)->toBe('varchar');
});

it('widens an existing bigint subject_id instead of recreating the table', function () {
    createStockSpatieActivityLogTable();

    expect(Schema::getColumnType('activity_log', 'subject_id'))->toBe('integer');

    runActivityLogMigration();

    expect(Schema::getColumnType('activity_log', 'subject_id'))->toBe('varchar');
});

it('is idempotent when run again after already widening the column', function () {
    createStockSpatieActivityLogTable();

    runActivityLogMigration();
    runActivityLogMigration();

    expect(Schema::getColumnType('activity_log', 'subject_id'))->toBe('varchar');
});

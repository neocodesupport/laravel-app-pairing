<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This package's Pairing/PairingRequest/PairingKey models use ULID (string)
 * primary keys. spatie/laravel-activitylog's own published migration types
 * `subject_id` as an unsigned bigint, which truncates ULIDs on strict
 * database engines (MySQL/PostgreSQL) — SQLite's loose typing hides this,
 * which is how it can pass a test suite and still fail in production.
 *
 * Host applications should not need to separately publish and run spatie's
 * own migration for this package to work: if the table does not exist yet,
 * this creates it with a ulid-compatible subject morph directly. If the
 * table already exists (e.g. the host app published spatie's migration
 * before installing this package, or uses activitylog for other features
 * too), this only widens `subject_id` so it can hold a ULID — every other
 * column, and the `causer` morph (typically an auto-incrementing user id),
 * is left untouched.
 *
 * The filename is intentionally dated far in the future so this always
 * runs after every other migration in the batch, package or host alike —
 * including a host's own (unrun) copy of spatie's stock activity_log
 * migration. That guarantees the `hasTable()` check above sees the final
 * state: if the host's own migration already created the table this run,
 * this one safely widens the column instead of racing it to create the
 * table first (which would make the host's own `Schema::create()` call
 * fail with "table already exists").
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('activitylog.table_name', 'activity_log');
        $connection = config('activitylog.database_connection');

        if (! Schema::connection($connection)->hasTable($table)) {
            Schema::connection($connection)->create($table, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable();
                $table->text('description');
                $table->nullableUlidMorphs('subject', 'subject');
                $table->string('event')->nullable();
                $table->nullableMorphs('causer', 'causer');
                $table->json('properties')->nullable();
                $table->uuid('batch_uuid')->nullable();
                $table->timestamps();
                $table->index('log_name');
            });

            return;
        }

        if (Schema::connection($connection)->hasColumn($table, 'subject_id')) {
            Schema::connection($connection)->table($table, function (Blueprint $table): void {
                $table->string('subject_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: this migration only ever widens an
        // existing column or creates the table when absent, neither of
        // which should be blindly reverted (the table may be shared with
        // other features by the time this runs `down`).
    }
};

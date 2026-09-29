<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Reminders were outside the brief's scope and have been removed.
 * Drops the table from databases that were migrated before the removal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('reminders');
    }

    public function down(): void
    {
        // The feature no longer exists, so there is nothing to restore.
    }
};

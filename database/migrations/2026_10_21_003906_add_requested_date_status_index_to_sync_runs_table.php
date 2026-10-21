<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('sync_runs', 'sync_runs_requested_date_status_index')) {
            return;
        }

        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->index(['requested_date', 'status'], 'sync_runs_requested_date_status_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('sync_runs', 'sync_runs_requested_date_status_index')) {
            return;
        }

        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->dropIndex('sync_runs_requested_date_status_index');
        });
    }
};

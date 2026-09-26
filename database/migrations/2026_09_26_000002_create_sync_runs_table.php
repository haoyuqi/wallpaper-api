<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source');
            $table->string('market');
            $table->date('requested_date');
            $table->string('status');
            $table->string('schema_version')->nullable();
            $table->jsonb('response_document')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};

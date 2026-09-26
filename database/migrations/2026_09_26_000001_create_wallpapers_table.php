<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallpapers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source');
            $table->string('source_item_id');
            $table->string('market');
            $table->date('source_date');
            $table->text('title')->nullable();
            $table->text('copyright_text')->nullable();
            $table->text('copyright_link')->nullable();
            $table->text('image_url');
            $table->text('download_url')->nullable();
            $table->string('schema_version');
            $table->jsonb('raw_payload');
            $table->timestampTz('retrieved_at');
            $table->timestampsTz();

            $table->unique(['source', 'market', 'source_date', 'source_item_id'], 'wallpapers_source_identity_unique');
            $table->index('source_date');
            $table->index(['source', 'source_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallpapers');
    }
};

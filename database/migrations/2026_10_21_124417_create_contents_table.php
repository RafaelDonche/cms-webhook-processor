<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->bigInteger('version');
            $table->enum('status', ['published', 'unpublished', 'deleted']);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('source_updated_at');
            $table->timestampTz('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'id']);
        });

        DB::statement('ALTER TABLE contents ADD CONSTRAINT contents_version_positive CHECK (version > 0)');

        DB::statement(<<<'SQL'
            ALTER TABLE contents ADD CONSTRAINT contents_payload_absent_only_when_deleted
            CHECK ((status = 'deleted') = (payload IS NULL))
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX contents_visible_to_users_index ON contents (id)
            WHERE status = 'published' AND disabled_at IS NULL
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};

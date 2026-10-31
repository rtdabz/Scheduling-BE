<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum', function (Blueprint $table): void {
            $table->string('cmo_number', 32)->nullable()->after('code');
            $table->unsignedSmallInteger('cmo_series')->nullable()->after('cmo_number');
        });

        // Prospective import defaults only; existing placements keep the legacy credit-derived durations.
        Schema::table('departments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('lecture_minutes_per_unit')->default(60);
            $table->unsignedSmallInteger('laboratory_minutes_per_unit')->default(180);
            $table->unsignedInteger('curriculum_import_settings_revision')->default(1);
        });

        Schema::create('curriculum_classification_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->enum('match_kind', ['exact', 'prefix']);
            $table->string('match_value', 64);
            $table->enum('category', ['major', 'minor']);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['department_id', 'match_kind', 'match_value'], 'curriculum_classification_rules_unique');
        });

        // Nullable so rows written before imports existed keep the verified legacy behavior.
        Schema::table('curriculum_course', function (Blueprint $table): void {
            $table->unsignedSmallInteger('lecture_units')->nullable();
            $table->unsignedSmallInteger('lab_units')->nullable();
            $table->unsignedSmallInteger('lecture_minutes')->nullable();
            $table->unsignedSmallInteger('laboratory_minutes')->nullable();
            $table->json('conversion_snapshot')->nullable();
            $table->text('prerequisites_text')->nullable();
        });

        Schema::create('curriculum_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('curriculum_id')->nullable()->constrained('curriculum')->nullOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_key', 100);
            $table->char('payload_hash', 64);
            $table->string('source_format', 8);
            $table->string('source_filename')->nullable();
            $table->char('source_sha256', 64)->nullable();
            $table->string('extractor_version', 64)->nullable();
            $table->json('record');
            $table->json('summary');
            $table->timestamps();
            $table->unique(['department_id', 'request_key'], 'curriculum_imports_request_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_imports');

        Schema::table('curriculum_course', function (Blueprint $table): void {
            $table->dropColumn([
                'lecture_units', 'lab_units', 'lecture_minutes', 'laboratory_minutes',
                'conversion_snapshot', 'prerequisites_text',
            ]);
        });

        Schema::dropIfExists('curriculum_classification_rules');

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn(['lecture_minutes_per_unit', 'laboratory_minutes_per_unit', 'curriculum_import_settings_revision']);
        });

        Schema::table('curriculum', function (Blueprint $table): void {
            $table->dropColumn(['cmo_number', 'cmo_series']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'faculties'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'honorific_prefix')) {
                    $blueprint->string('honorific_prefix', 20)->nullable()->after('suffix');
                }
                if (! Schema::hasColumn($table, 'credentials')) {
                    $blueprint->string('credentials', 100)->nullable()->after('honorific_prefix');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'faculties'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $columns = array_values(array_filter(
                    ['honorific_prefix', 'credentials'],
                    fn (string $column): bool => Schema::hasColumn($table, $column),
                ));
                if ($columns !== []) {
                    $blueprint->dropColumn($columns);
                }
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a department with several programs shares its rooms between them.
 *
 * A room may name a home program: the program whose classes go there first. A
 * room with no home program is shared by every program of the department. The
 * department's room_sharing_policy decides what the home program means:
 *
 *  - open:       rooms are one pool, as before (the default, so nothing changes
 *                until the secretary opts in);
 *  - home_first: home room, then a shared room, then another program's vacant
 *                room as a suggestion the program confirms;
 *  - strict:     home rooms and shared rooms only.
 *
 * The home program lives on the room rather than per semester so the secretary
 * does not redo it every term; it changes only when the arrangement does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rooms', 'home_program_id')) {
            Schema::table('rooms', function (Blueprint $table): void {
                $table->foreignId('home_program_id')->nullable()->after('department_id')
                    ->constrained('programs')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('departments', 'room_sharing_policy')) {
            Schema::table('departments', function (Blueprint $table): void {
                $table->string('room_sharing_policy', 16)->default('open');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rooms', 'home_program_id')) {
            Schema::table('rooms', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('home_program_id');
            });
        }

        if (Schema::hasColumn('departments', 'room_sharing_policy')) {
            Schema::table('departments', function (Blueprint $table): void {
                $table->dropColumn('room_sharing_policy');
            });
        }
    }
};

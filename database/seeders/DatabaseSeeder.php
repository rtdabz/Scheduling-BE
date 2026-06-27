<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            VpaaSeeder::class,
            DepartmentSeeder::class,
            RoomSeeder::class,
            FacultySeeder::class,
            SubjectSeeder::class,
            TermSeeder::class,
            SectionSeeder::class,
            SubjectSeeder::class,
        ]);
    }
}

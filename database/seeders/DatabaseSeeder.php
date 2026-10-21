<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Demo Admin',
            'email' => 'admin@example.com',
        ]);

        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'user@example.com',
        ]);

        Content::factory()->count(10)->published()->create();
        Content::factory()->count(3)->unpublished()->create();
        Content::factory()->count(2)->published()->disabled($admin)->create();
        Content::factory()->count(2)->deleted()->create();
    }
}

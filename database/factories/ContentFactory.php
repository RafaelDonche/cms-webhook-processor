<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Content>
 */
final class ContentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'content-'.fake()->unique()->numberBetween(1, 999_999),
            'version' => fake()->numberBetween(1, 20),
            'status' => ContentStatus::Published,
            'payload' => (object) [
                'title' => fake()->sentence(),
                'description' => fake()->paragraph(),
            ],
            'source_updated_at' => fake()->dateTimeBetween('-1 month'),
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }

    public function unpublished(): static
    {
        return $this->state(['status' => ContentStatus::Unpublished]);
    }

    public function deleted(): static
    {
        return $this->state([
            'status' => ContentStatus::Deleted,
            'payload' => null,
        ]);
    }

    public function disabled(?User $admin = null): static
    {
        return $this->state(fn (): array => [
            'disabled_at' => now(),
            'disabled_by' => $admin ?? User::factory()->admin(),
        ]);
    }
}

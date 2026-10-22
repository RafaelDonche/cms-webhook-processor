<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ListContentsRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_accepts_the_maximum_page_size_of_100(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/contents?per_page=100')->assertOk();
    }

    #[DataProvider('invalidPageSizes')]
    public function test_returns_422_for_page_sizes_outside_1_to_100(string $perPage): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/contents?per_page={$perPage}")
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['per_page']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPageSizes(): array
    {
        return [
            'zero' => ['0'],
            'above the maximum' => ['101'],
            'not a number' => ['many'],
        ];
    }

    public function test_returns_422_when_users_send_admin_filters(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/contents?filter[status]=published')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter' => 'The filter field is prohibited.']);
    }

    #[DataProvider('invalidAdminFilters')]
    public function test_returns_422_for_invalid_admin_filters(string $query, string $invalidField): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/contents?{$query}")
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$invalidField]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidAdminFilters(): array
    {
        return [
            'deleted status' => ['filter[status]=deleted', 'filter.status'],
            'unknown status' => ['filter[status]=archived', 'filter.status'],
            'non boolean disabled' => ['filter[disabled]=maybe', 'filter.disabled'],
            'unknown filter' => ['filter[author]=someone', 'filter'],
            'filter as a string' => ['filter=published', 'filter'],
        ];
    }
}

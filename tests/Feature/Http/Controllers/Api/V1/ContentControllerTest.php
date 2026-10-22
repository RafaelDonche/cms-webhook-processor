<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ContentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_users_list_only_published_content_that_is_not_disabled(): void
    {
        Content::factory()->published()->create(['external_id' => 'published']);
        Content::factory()->published()->disabled()->create(['external_id' => 'disabled']);
        Content::factory()->unpublished()->create(['external_id' => 'unpublished']);
        Content::factory()->deleted()->create(['external_id' => 'deleted']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/contents')
            ->assertOk()
            ->assertJsonPath('data.*.id', ['published']);
    }

    public function test_admins_list_published_and_unpublished_content_including_disabled(): void
    {
        Content::factory()->published()->create(['external_id' => 'published']);
        Content::factory()->published()->disabled()->create(['external_id' => 'disabled']);
        Content::factory()->unpublished()->create(['external_id' => 'unpublished']);
        Content::factory()->deleted()->create(['external_id' => 'deleted']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/contents')
            ->assertOk()
            ->assertJsonPath('data.*.id', ['published', 'disabled', 'unpublished']);
    }

    public function test_paginates_with_a_cursor_that_keeps_the_page_size(): void
    {
        Content::factory()->published()->create(['external_id' => 'first']);
        Content::factory()->published()->create(['external_id' => 'second']);
        Content::factory()->published()->create(['external_id' => 'third']);
        Sanctum::actingAs(User::factory()->create());

        $firstPage = $this->getJson('/api/v1/contents?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.*.id', ['first', 'second'])
            ->assertJsonPath('meta.per_page', 2);

        $this->getJson((string) $firstPage->json('links.next'))
            ->assertOk()
            ->assertJsonPath('data.*.id', ['third'])
            ->assertJsonPath('links.next', null);
    }

    public function test_lists_15_contents_per_page_by_default(): void
    {
        Content::factory()->count(16)->published()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/contents')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_admins_can_filter_contents_by_status(): void
    {
        Content::factory()->published()->create(['external_id' => 'published']);
        Content::factory()->unpublished()->create(['external_id' => 'unpublished']);
        Content::factory()->unpublished()->disabled()->create(['external_id' => 'unpublished-disabled']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/contents?filter[status]=unpublished')
            ->assertOk()
            ->assertJsonPath('data.*.id', ['unpublished', 'unpublished-disabled']);
    }

    /**
     * @param  list<string>  $expectedIds
     */
    #[DataProvider('disabledFilters')]
    public function test_admins_can_filter_contents_by_disabled_state(string $filter, array $expectedIds): void
    {
        Content::factory()->published()->create(['external_id' => 'enabled']);
        Content::factory()->published()->disabled()->create(['external_id' => 'disabled']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/contents?filter[disabled]={$filter}")
            ->assertOk()
            ->assertJsonPath('data.*.id', $expectedIds);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function disabledFilters(): array
    {
        return [
            'only disabled' => ['true', ['disabled']],
            'only enabled' => ['false', ['enabled']],
        ];
    }

    public function test_shows_published_content_to_users_without_the_local_override_fields(): void
    {
        Content::factory()->published()->create([
            'external_id' => 'content-123',
            'version' => 3,
            'payload' => json_decode('{"title": "Example", "meta": {}, "tags": []}'),
            'source_updated_at' => CarbonImmutable::parse('2026-01-01T10:00:00Z'),
        ]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/contents/content-123');

        $response->assertOk()->assertExactJson(['data' => [
            'id' => 'content-123',
            'version' => 3,
            'status' => 'published',
            'payload' => ['title' => 'Example', 'meta' => [], 'tags' => []],
            'source_updated_at' => '2026-01-01T10:00:00Z',
        ]]);
        $this->assertStringContainsString('"meta":{},"tags":[]', (string) $response->getContent());
    }

    public function test_shows_the_local_override_to_admins(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01T12:00:00Z'));
        Content::factory()->unpublished()->disabled()->create(['external_id' => 'content-123', 'version' => 4]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/contents/content-123')
            ->assertOk()
            ->assertJsonPath('data.status', 'unpublished')
            ->assertJsonPath('data.disabled', true)
            ->assertJsonPath('data.disabled_at', '2026-02-01T12:00:00Z');
    }

    /**
     * @param  Closure(): User  $consumer
     */
    #[DataProvider('contentsHiddenFromConsumers')]
    public function test_returns_404_for_content_the_consumer_cannot_see(Closure $consumer, ?Closure $content): void
    {
        if ($content !== null) {
            $content();
        }
        Sanctum::actingAs($consumer());

        $this->getJson('/api/v1/contents/content-123')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    /**
     * @return array<string, array{Closure(): User, ?Closure(): Content}>
     */
    public static function contentsHiddenFromConsumers(): array
    {
        $user = static fn (): User => User::factory()->create();
        $admin = static fn (): User => User::factory()->admin()->create();

        return [
            'unpublished content for a user' => [$user, static fn (): Content => Content::factory()->unpublished()->create(['external_id' => 'content-123'])],
            'disabled content for a user' => [$user, static fn (): Content => Content::factory()->published()->disabled()->create(['external_id' => 'content-123'])],
            'deleted content for a user' => [$user, static fn (): Content => Content::factory()->deleted()->create(['external_id' => 'content-123'])],
            'unknown content for a user' => [$user, null],
            'deleted content for an admin' => [$admin, static fn (): Content => Content::factory()->deleted()->create(['external_id' => 'content-123'])],
            'unknown content for an admin' => [$admin, null],
        ];
    }

    #[DataProvider('contentWriteRequests')]
    public function test_returns_405_for_any_attempt_to_modify_cms_content(string $method, string $uri): void
    {
        $content = Content::factory()->published()->create(['external_id' => 'content-123', 'version' => 3]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->json($method, $uri, ['version' => 9, 'payload' => ['title' => 'Tampered']])->assertMethodNotAllowed();

        $content->refresh();
        $this->assertSame(3, $content->version);
        $this->assertNotSame('Tampered', $content->payload?->title);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function contentWriteRequests(): array
    {
        return [
            'create' => ['POST', '/api/v1/contents'],
            'replace' => ['PUT', '/api/v1/contents/content-123'],
            'update' => ['PATCH', '/api/v1/contents/content-123'],
            'delete' => ['DELETE', '/api/v1/contents/content-123'],
        ];
    }

    public function test_returns_429_once_a_consumer_exceeds_the_rate_limit_without_affecting_others(): void
    {
        config(['api.rate_limit_per_minute' => 2]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->getJson('/api/v1/contents')->assertOk();
        $this->getJson('/api/v1/contents')->assertOk();
        $this->getJson('/api/v1/contents')->assertTooManyRequests();

        Sanctum::actingAs($second);
        $this->getJson('/api/v1/contents')->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Logging\ContentDisablementLogger;
use App\Models\Content;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCmsWebhook;
use Tests\TestCase;

final class ContentDisablementControllerTest extends TestCase
{
    use InteractsWithCmsWebhook;
    use LazilyRefreshDatabase;

    public function test_admins_disable_content_and_the_change_is_logged(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01T12:00:00Z'));
        $log = $this->captureDisablementLog();
        $admin = User::factory()->admin()->create();
        $content = Content::factory()->published()->create(['external_id' => 'content-123']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/contents/content-123/disabled')
            ->assertOk()
            ->assertJsonPath('data.id', 'content-123')
            ->assertJsonPath('data.disabled', true)
            ->assertJsonPath('data.disabled_at', '2026-02-01T12:00:00Z');

        $content->refresh();
        $this->assertSame('2026-02-01T12:00:00+00:00', $content->disabled_at?->toAtomString());
        $this->assertSame($admin->id, $content->disabled_by);
        $this->assertSame(['content.disabled'], array_map(static fn ($record): string => $record->message, $log->getRecords()));
        $this->assertSame(['external_id' => 'content-123', 'admin_id' => $admin->id], $log->getRecords()[0]->context);
    }

    public function test_disabling_twice_keeps_the_original_override_and_logs_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01T12:00:00Z'));
        $log = $this->captureDisablementLog();
        $firstAdmin = User::factory()->admin()->create();
        $content = Content::factory()->published()->disabled($firstAdmin)->create(['external_id' => 'content-123']);
        $this->travelTo(CarbonImmutable::parse('2026-02-02T12:00:00Z'));
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/contents/content-123/disabled')
            ->assertOk()
            ->assertJsonPath('data.disabled_at', '2026-02-01T12:00:00Z');

        $content->refresh();
        $this->assertSame($firstAdmin->id, $content->disabled_by);
        $this->assertSame([], $log->getRecords());
    }

    public function test_admins_enable_content_and_the_change_is_logged(): void
    {
        $log = $this->captureDisablementLog();
        $admin = User::factory()->admin()->create();
        $content = Content::factory()->published()->disabled()->create(['external_id' => 'content-123']);
        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/contents/content-123/disabled')
            ->assertOk()
            ->assertJsonPath('data.disabled', false)
            ->assertJsonPath('data.disabled_at', null);

        $content->refresh();
        $this->assertFalse($content->isDisabled());
        $this->assertNull($content->disabled_by);
        $this->assertSame(['content.enabled'], array_map(static fn ($record): string => $record->message, $log->getRecords()));
        $this->assertSame(['external_id' => 'content-123', 'admin_id' => $admin->id], $log->getRecords()[0]->context);
    }

    public function test_enabling_content_that_is_not_disabled_changes_nothing(): void
    {
        $log = $this->captureDisablementLog();
        Content::factory()->published()->create(['external_id' => 'content-123']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson('/api/v1/contents/content-123/disabled')
            ->assertOk()
            ->assertJsonPath('data.disabled', false);

        $this->assertSame([], $log->getRecords());
    }

    public function test_admins_can_disable_unpublished_content(): void
    {
        Content::factory()->unpublished()->create(['external_id' => 'content-123']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/contents/content-123/disabled')
            ->assertOk()
            ->assertJsonPath('data.disabled', true);
    }

    public function test_returns_404_when_admins_disable_deleted_content(): void
    {
        Content::factory()->deleted()->create(['external_id' => 'content-123']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/contents/content-123/disabled')->assertNotFound();
    }

    #[DataProvider('disablementMethods')]
    public function test_returns_403_when_users_manage_the_disable_state(string $method): void
    {
        $content = Content::factory()->published()->create(['external_id' => 'content-123']);
        Sanctum::actingAs(User::factory()->create());

        $this->json($method, '/api/v1/contents/content-123/disabled')->assertForbidden();

        $content->refresh();
        $this->assertFalse($content->isDisabled());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disablementMethods(): array
    {
        return [
            'disable' => ['PUT'],
            'enable' => ['DELETE'],
        ];
    }

    public function test_users_lose_and_regain_access_as_admins_toggle_the_override(): void
    {
        Content::factory()->published()->create(['external_id' => 'content-123']);
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/contents/content-123/disabled')->assertOk();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/contents/content-123')->assertNotFound();
        $this->getJson('/api/v1/contents')->assertJsonPath('data', []);

        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/contents/content-123/disabled')->assertOk();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/contents/content-123')->assertOk();
        $this->getJson('/api/v1/contents')->assertJsonPath('data.*.id', ['content-123']);
    }

    public function test_the_override_survives_later_cms_events(): void
    {
        $this->configureCmsWebhookCredentials();
        $this->postCmsEvents([self::publishEvent(['id' => 'content-123', 'version' => 1])])->assertOk();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson('/api/v1/contents/content-123/disabled')->assertOk();

        $this->postCmsEvents([
            self::publishEvent(['type' => 'unpublish', 'id' => 'content-123', 'version' => 2]),
            self::publishEvent(['id' => 'content-123', 'version' => 3, 'payload' => ['title' => 'Republished']]),
        ])->assertOk();

        $this->getJson('/api/v1/contents/content-123')
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.payload.title', 'Republished')
            ->assertJsonPath('data.disabled', true);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/contents/content-123')->assertNotFound();
    }

    private function captureDisablementLog(): TestHandler
    {
        $handler = new TestHandler;

        $this->app->instance(ContentDisablementLogger::class, new ContentDisablementLogger(new Logger('test', [$handler])));

        return $handler;
    }
}

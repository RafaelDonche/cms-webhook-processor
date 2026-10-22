<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class IssueConsumerTokenCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_prints_a_token_that_authenticates_the_consumer_once(): void
    {
        User::factory()->create(['email' => 'mobile@example.com']);

        $exitCode = Artisan::call('consumers:token', ['email' => 'mobile@example.com']);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Store it now: it cannot be displayed again.', $output);
        $this->withToken($this->lastLine($output))->getJson('/api/v1/contents')->assertOk();
    }

    public function test_stores_only_a_hash_of_the_token(): void
    {
        User::factory()->create(['email' => 'mobile@example.com']);

        Artisan::call('consumers:token', ['email' => 'mobile@example.com', '--name' => 'mobile']);

        [, $secret] = explode('|', $this->lastLine(Artisan::output()), 2);
        $stored = PersonalAccessToken::query()->sole();
        $this->assertSame('mobile', $stored->name);
        $this->assertSame(hash('sha256', $secret), $stored->token);
    }

    public function test_expires_the_token_after_the_configured_default_lifetime(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
        config(['api.token_ttl_days' => 30]);
        User::factory()->create(['email' => 'mobile@example.com']);

        $this->mockArtisan('consumers:token', ['email' => 'mobile@example.com'])
            ->expectsOutputToContain('expiring at 2026-01-31T00:00:00Z')
            ->assertSuccessful();

        $this->assertSame('2026-01-31T00:00:00+00:00', PersonalAccessToken::query()->sole()->expires_at?->toAtomString());
    }

    public function test_expires_the_token_after_the_requested_lifetime(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:00Z'));
        User::factory()->create(['email' => 'mobile@example.com']);

        $this->mockArtisan('consumers:token', ['email' => 'mobile@example.com', '--expires-in-days' => '7'])->assertSuccessful();

        $this->assertSame('2026-01-08T00:00:00+00:00', PersonalAccessToken::query()->sole()->expires_at?->toAtomString());
    }

    #[DataProvider('invalidLifetimes')]
    public function test_refuses_lifetimes_outside_1_to_365_days(string $days): void
    {
        User::factory()->create(['email' => 'mobile@example.com']);

        $this->mockArtisan('consumers:token', ['email' => 'mobile@example.com', '--expires-in-days' => $days])->assertFailed();

        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidLifetimes(): array
    {
        return [
            'zero days' => ['0'],
            'more than a year' => ['366'],
            'not a number' => ['forever'],
        ];
    }

    public function test_refuses_an_unknown_consumer(): void
    {
        $this->mockArtisan('consumers:token', ['email' => 'unknown@example.com'])
            ->expectsOutputToContain('No consumer is registered with that email.')
            ->assertFailed();

        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    private function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];

        return trim((string) end($lines));
    }
}

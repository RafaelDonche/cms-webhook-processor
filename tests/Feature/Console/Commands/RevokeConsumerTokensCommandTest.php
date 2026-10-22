<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class RevokeConsumerTokensCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_revokes_every_token_of_the_consumer_only(): void
    {
        $consumer = User::factory()->create(['email' => 'mobile@example.com']);
        $token = $consumer->createToken('first', expiresAt: now()->addDay())->plainTextToken;
        $consumer->createToken('second', expiresAt: now()->addDay());
        $other = User::factory()->create();
        $other->createToken('other', expiresAt: now()->addDay());

        $this->mockArtisan('consumers:revoke', ['email' => 'mobile@example.com'])
            ->expectsOutputToContain('Revoked 2 token(s) of [mobile@example.com].')
            ->assertSuccessful();

        $this->assertSame(0, $consumer->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->withToken($token)->getJson('/api/v1/contents')->assertUnauthorized();
    }

    public function test_refuses_an_unknown_consumer(): void
    {
        $this->mockArtisan('consumers:revoke', ['email' => 'unknown@example.com'])
            ->expectsOutputToContain('No consumer is registered with that email.')
            ->assertFailed();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class CreateConsumerCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_a_consumer_with_the_user_role_by_default(): void
    {
        $this->mockArtisan('consumers:create', ['name' => 'Mobile App', 'email' => 'mobile@example.com'])
            ->expectsOutputToContain('Consumer [mobile@example.com] created with the [user] role.')
            ->assertSuccessful();

        $this->assertSame(UserRole::User, User::query()->where('email', 'mobile@example.com')->sole()->role);
    }

    public function test_creates_an_administrator_when_requested(): void
    {
        $this->mockArtisan('consumers:create', ['name' => 'Back Office', 'email' => 'office@example.com', '--admin' => true])
            ->expectsOutputToContain('created with the [admin] role.')
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, User::query()->where('email', 'office@example.com')->sole()->role);
    }

    public function test_refuses_an_email_that_is_already_registered(): void
    {
        User::factory()->create(['email' => 'mobile@example.com']);

        $this->mockArtisan('consumers:create', ['name' => 'Another App', 'email' => 'mobile@example.com'])
            ->expectsOutputToContain('The email has already been taken.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_refuses_an_invalid_email(): void
    {
        $this->mockArtisan('consumers:create', ['name' => 'Mobile App', 'email' => 'not-an-email'])
            ->expectsOutputToContain('The email field must be a valid email address.')
            ->assertFailed();

        $this->assertDatabaseEmpty('users');
    }
}

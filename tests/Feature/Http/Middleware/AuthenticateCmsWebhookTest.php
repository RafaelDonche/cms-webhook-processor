<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesCmsLog;
use Tests\Concerns\InteractsWithCmsWebhook;
use Tests\TestCase;

final class AuthenticateCmsWebhookTest extends TestCase
{
    use CapturesCmsLog;
    use InteractsWithCmsWebhook;
    use LazilyRefreshDatabase;

    private const string CHALLENGE = 'Basic realm="cms-webhook", charset="UTF-8"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCmsWebhookCredentials();
    }

    public function test_accepts_valid_credentials(): void
    {
        $this->postCmsEvents([self::publishEvent()])->assertOk();
    }

    public function test_returns_401_with_a_basic_challenge_when_credentials_are_missing(): void
    {
        $this->postJson('/cms/events', [self::publishEvent()])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', self::CHALLENGE)
            ->assertExactJson(['message' => 'Invalid CMS webhook credentials.']);
    }

    #[DataProvider('invalidCredentials')]
    public function test_returns_401_for_invalid_credentials(string $username, string $password): void
    {
        $this->withBasicAuth($username, $password)
            ->postJson('/cms/events', [self::publishEvent()])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', self::CHALLENGE);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidCredentials(): array
    {
        return [
            'wrong username' => ['someone-else', self::CMS_PASSWORD],
            'wrong password' => [self::CMS_USERNAME, 'wrong-password'],
            'empty credentials' => ['', ''],
        ];
    }

    public function test_returns_401_for_bearer_tokens(): void
    {
        $this->withToken('consumer-api-token')
            ->postJson('/cms/events', [self::publishEvent()])
            ->assertUnauthorized();
    }

    public function test_returns_401_while_webhook_credentials_are_not_configured(): void
    {
        config(['cms.webhook.username' => '', 'cms.webhook.password' => '']);

        $this->withBasicAuth('', '')
            ->postJson('/cms/events', [self::publishEvent()])
            ->assertUnauthorized();
    }

    public function test_authenticates_before_validating_the_batch(): void
    {
        $this->postJson('/cms/events', [])->assertUnauthorized();
    }

    public function test_logs_the_failure_reason_without_the_submitted_credentials(): void
    {
        $log = $this->captureCmsLog();

        $this->withBasicAuth(self::CMS_USERNAME, 'wrong-password')->postJson('/cms/events', [self::publishEvent()]);

        $this->assertCount(1, $log->getRecords());
        $record = $log->getRecords()[0];
        $this->assertSame(Level::Warning, $record->level);
        $this->assertSame('cms.auth.failed', $record->message);
        $this->assertSame(['reason' => 'invalid_credentials', 'client_ip' => '127.0.0.1'], $record->context);
        $this->assertStringNotContainsString('wrong-password', (string) json_encode($log->getRecords()));
    }

    public function test_logs_missing_configuration_as_an_error(): void
    {
        config(['cms.webhook.password' => '']);
        $log = $this->captureCmsLog();

        $this->postCmsEvents([self::publishEvent()]);

        $this->assertTrue($log->hasErrorThatContains('cms.auth.failed'));
        $this->assertSame('credentials_not_configured', $log->getRecords()[0]->context['reason']);
    }
}

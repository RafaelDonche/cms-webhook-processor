<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\CmsWebhookCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CmsWebhookCredentialsTest extends TestCase
{
    public function test_matches_the_configured_username_and_password(): void
    {
        $credentials = new CmsWebhookCredentials('cms', 's3cret');

        $this->assertTrue($credentials->matches('cms', 's3cret'));
    }

    #[DataProvider('wrongCredentials')]
    public function test_rejects_credentials_that_differ_from_the_configured_ones(string $username, string $password): void
    {
        $credentials = new CmsWebhookCredentials('cms', 's3cret');

        $this->assertFalse($credentials->matches($username, $password));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function wrongCredentials(): array
    {
        return [
            'wrong username' => ['other', 's3cret'],
            'wrong password' => ['cms', 'wrong'],
            'password with different case' => ['cms', 'S3CRET'],
            'password prefix' => ['cms', 's3c'],
            'empty values' => ['', ''],
        ];
    }

    #[DataProvider('incompleteConfigurations')]
    public function test_rejects_every_credential_while_not_fully_configured(string $username, string $password): void
    {
        $credentials = new CmsWebhookCredentials($username, $password);

        $this->assertFalse($credentials->isConfigured());
        $this->assertFalse($credentials->matches($username, $password));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function incompleteConfigurations(): array
    {
        return [
            'nothing configured' => ['', ''],
            'missing password' => ['cms', ''],
            'missing username' => ['', 's3cret'],
        ];
    }
}

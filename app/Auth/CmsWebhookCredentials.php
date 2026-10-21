<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Container\Attributes\Config;
use SensitiveParameter;

final readonly class CmsWebhookCredentials
{
    public function __construct(
        #[Config('cms.webhook.username')]
        private string $username,
        #[Config('cms.webhook.password'), SensitiveParameter]
        private string $password,
    ) {}

    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    public function matches(string $username, #[SensitiveParameter] string $password): bool
    {
        $usernameMatches = $this->equalsInConstantTime($this->username, $username);
        $passwordMatches = $this->equalsInConstantTime($this->password, $password);

        return $this->isConfigured() && $usernameMatches && $passwordMatches;
    }

    /**
     * Comparing fixed-length digests keeps the comparison time independent of the secret's length.
     */
    private function equalsInConstantTime(#[SensitiveParameter] string $known, #[SensitiveParameter] string $given): bool
    {
        return hash_equals(hash('sha256', $known), hash('sha256', $given));
    }
}

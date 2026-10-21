<?php

declare(strict_types=1);

namespace App\Enums;

use Psr\Log\LogLevel;

enum WebhookAuthFailure: string
{
    case NotConfigured = 'credentials_not_configured';
    case MissingCredentials = 'missing_credentials';
    case InvalidCredentials = 'invalid_credentials';

    public function logLevel(): string
    {
        return $this === self::NotConfigured ? LogLevel::ERROR : LogLevel::WARNING;
    }
}

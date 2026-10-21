<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\CmsWebhookCredentials;
use App\Enums\WebhookAuthFailure;
use App\Logging\CmsWebhookLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateCmsWebhook
{
    private const string CHALLENGE = 'Basic realm="cms-webhook", charset="UTF-8"';

    public function __construct(
        private CmsWebhookCredentials $credentials,
        private CmsWebhookLogger $logger,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $failure = $this->authenticationFailure($request);

        if ($failure === null) {
            return $next($request);
        }

        $this->logger->authenticationFailed($failure, $request->ip());

        return new JsonResponse(
            ['message' => 'Invalid CMS webhook credentials.'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => self::CHALLENGE],
        );
    }

    private function authenticationFailure(Request $request): ?WebhookAuthFailure
    {
        $username = $request->getUser();

        return match (true) {
            ! $this->credentials->isConfigured() => WebhookAuthFailure::NotConfigured,
            $username === null => WebhookAuthFailure::MissingCredentials,
            ! $this->credentials->matches($username, $request->getPassword() ?? '') => WebhookAuthFailure::InvalidCredentials,
            default => null,
        };
    }
}

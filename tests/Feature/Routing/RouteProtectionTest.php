<?php

declare(strict_types=1);

namespace Tests\Feature\Routing;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

final class RouteProtectionTest extends TestCase
{
    private const array AUTHENTICATION_MIDDLEWARE = ['auth.cms', 'auth:sanctum'];

    public function test_every_registered_route_requires_authentication(): void
    {
        $unprotectedRoutes = collect(Router::getRoutes()->getRoutes())
            ->reject(fn (Route $route): bool => array_intersect(self::AUTHENTICATION_MIDDLEWARE, $route->gatherMiddleware()) !== [])
            ->map(fn (Route $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unprotectedRoutes);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ErrorRenderingTest extends TestCase
{
    #[DataProvider('unregisteredPaths')]
    public function test_unregistered_paths_respond_with_json_not_found(string $path): void
    {
        $this->get($path)
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unregisteredPaths(): array
    {
        return [
            'root' => ['/'],
            'framework health check' => ['/up'],
            'unknown resource' => ['/api/unknown'],
        ];
    }
}

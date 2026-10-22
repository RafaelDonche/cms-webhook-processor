<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deliberately carries no previous exception: database errors echo the failing row, which holds CMS content.
 * The cause is recorded without that content in the CMS log before this is thrown.
 */
final class CmsBatchProcessingFailed extends RuntimeException implements ShouldntReport
{
    private function __construct(
        public readonly string $batchId,
    ) {
        parent::__construct("CMS batch [{$batchId}] could not be processed; no events were applied.");
    }

    public static function forBatch(string $batchId): self
    {
        return new self($batchId);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'The batch could not be processed and no events were applied. Retry the delivery.',
            'batch_id' => $this->batchId,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}

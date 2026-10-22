<?php

declare(strict_types=1);

namespace App\Http\Resources\Cms;

use App\Data\BatchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BatchResultResource extends JsonResource
{
    public function __construct(
        private readonly BatchResult $batch,
    ) {
        parent::__construct($batch);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'batch_id' => $this->batch->batchId,
            'received' => count($this->batch->events),
            'applied' => $this->batch->appliedCount(),
            'ignored' => $this->batch->ignoredCount(),
            'events' => EventResultResource::collection($this->batch->events),
        ];
    }
}

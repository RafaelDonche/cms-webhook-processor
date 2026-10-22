<?php

declare(strict_types=1);

namespace App\Http\Resources\Cms;

use App\Data\EventResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EventResultResource extends JsonResource
{
    public function __construct(
        private readonly EventResult $event,
    ) {
        parent::__construct($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->event->externalId,
            'type' => $this->event->type,
            'version' => $this->event->version,
            'outcome' => $this->event->outcome,
            'reason' => $this->when($this->event->reason !== null, $this->event->reason),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Content;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContentResource extends JsonResource
{
    public function __construct(
        private readonly Content $content,
    ) {
        parent::__construct($content);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->content->external_id,
            'version' => $this->content->version,
            'status' => $this->content->status,
            'payload' => $this->content->payload,
            'source_updated_at' => $this->content->source_updated_at?->toIso8601ZuluString(),
            $this->mergeWhen($this->isAdmin($request), fn (): array => [
                'disabled' => $this->content->isDisabled(),
                'disabled_at' => $this->content->disabled_at?->toIso8601ZuluString(),
            ]),
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->isAdmin();
    }
}

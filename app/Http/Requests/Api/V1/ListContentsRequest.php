<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ContentStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListContentsRequest extends FormRequest
{
    public const int DEFAULT_PER_PAGE = 15;

    public const int MAX_PER_PAGE = 100;

    private const array BOOLEAN_QUERY_VALUES = ['true', 'false', '1', '0'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paginationRules = [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string'],
        ];

        if (! $this->isFromAdmin()) {
            return $paginationRules + ['filter' => ['prohibited']];
        }

        return $paginationRules + [
            'filter' => ['sometimes', 'array:status,disabled'],
            'filter.status' => ['sometimes', Rule::enum(ContentStatus::class)->only([ContentStatus::Published, ContentStatus::Unpublished])],
            'filter.disabled' => ['sometimes', Rule::in(self::BOOLEAN_QUERY_VALUES)],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    public function statusFilter(): ?ContentStatus
    {
        return $this->enum('filter.status', ContentStatus::class);
    }

    public function disabledFilter(): ?bool
    {
        return $this->has('filter.disabled') ? $this->boolean('filter.disabled') : null;
    }

    private function isFromAdmin(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isAdmin();
    }
}

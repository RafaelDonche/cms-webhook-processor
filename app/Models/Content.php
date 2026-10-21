<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\CmsEvent;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Exceptions\OutdatedContentEvent;
use Database\Factories\ContentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

final class Content extends Model
{
    /** @use HasFactory<ContentFactory> */
    use HasFactory;

    public static function forExternalId(string $externalId): self
    {
        $content = new self;
        $content->external_id = $externalId;

        return $content;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function acceptsVersion(int $version): bool
    {
        return ! $this->exists || $version > $this->version;
    }

    public function applyEvent(CmsEvent $event): void
    {
        if ($event->externalId !== $this->external_id) {
            throw new InvalidArgumentException(
                "Event for content [{$event->externalId}] cannot be applied to content [{$this->external_id}]."
            );
        }

        if (! $this->acceptsVersion($event->version)) {
            throw OutdatedContentEvent::forVersion($this->external_id, $this->version, $event->version);
        }

        $this->version = $event->version;
        $this->status = $event->type->resultingStatus();
        $this->payload = $event->payload;
        $this->source_updated_at = $event->timestamp;
    }

    public function disable(User $admin): void
    {
        if ($this->isDisabled()) {
            return;
        }

        $this->disabled_at = now();
        $this->disabledBy()->associate($admin);
    }

    public function enable(): void
    {
        $this->disabled_at = null;
        $this->disabledBy()->dissociate();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            UserRole::Admin => $query->whereIn('status', [ContentStatus::Published, ContentStatus::Unpublished]),
            UserRole::User => $query->where('status', ContentStatus::Published)->whereNull('disabled_at'),
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => ContentStatus::class,
            'payload' => 'object',
            'source_updated_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }
}

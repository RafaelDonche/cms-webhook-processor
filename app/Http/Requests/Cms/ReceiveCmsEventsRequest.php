<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Data\CmsEvent;
use App\Enums\CmsEventType;
use App\Logging\CmsWebhookLogger;
use App\Rules\Rfc3339Timestamp;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use stdClass;

final class ReceiveCmsEventsRequest extends FormRequest
{
    public const int MAX_BATCH_SIZE = 100;

    /**
     * @var list<mixed>|null
     */
    private ?array $decodedEvents = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return ['events' => json_decode($this->getContent(), associative: true)];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $batchRules = ['events' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_BATCH_SIZE]];

        if ($this->decodedEvents() === []) {
            return $batchRules;
        }

        return $batchRules + [
            'events.*' => ['array'],
            'events.*.type' => ['required', Rule::enum(CmsEventType::class)],
            'events.*.id' => ['required', 'string', 'max:255'],
            'events.*.version' => ['required', 'integer:strict', 'min:1'],
            'events.*.timestamp' => ['required', new Rfc3339Timestamp],
            'events.*.payload' => ['present_if:events.*.type,'.implode(',', $this->payloadCarryingTypes())],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->ensurePayloadsAreObjects(...)];
    }

    /**
     * @return list<CmsEvent>
     */
    public function toEvents(): array
    {
        /** @var list<array{type: string, id: string, version: int, timestamp: string}> $events */
        $events = $this->validated('events');

        return array_map(fn (array $event, int $index): CmsEvent => new CmsEvent(
            type: CmsEventType::from($event['type']),
            externalId: $event['id'],
            version: $event['version'],
            timestamp: CarbonImmutable::parse($event['timestamp']),
            payload: $this->objectPayloadAt($index),
        ), $events, array_keys($events));
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->container->make(CmsWebhookLogger::class)->batchRejected(
            count($this->decodedEvents()),
            array_keys($validator->errors()->messages()),
        );

        parent::failedValidation($validator);
    }

    private function ensurePayloadsAreObjects(Validator $validator): void
    {
        foreach ($this->decodedEvents() as $index => $event) {
            if (! $event instanceof stdClass || ! property_exists($event, 'payload')) {
                continue;
            }

            $type = is_string($event->type ?? null) ? CmsEventType::tryFrom($event->type) : null;

            if ($type?->carriesPayload() && ! $event->payload instanceof stdClass) {
                $validator->errors()->add(
                    "events.{$index}.payload",
                    "The events.{$index}.payload field must be a JSON object.",
                );
            }
        }
    }

    private function objectPayloadAt(int $index): ?stdClass
    {
        $event = $this->decodedEvents()[$index] ?? null;
        $payload = $event instanceof stdClass ? ($event->payload ?? null) : null;

        return $payload instanceof stdClass ? $payload : null;
    }

    /**
     * @return list<mixed>
     */
    private function decodedEvents(): array
    {
        if ($this->decodedEvents === null) {
            $decoded = json_decode($this->getContent());
            $this->decodedEvents = is_array($decoded) && array_is_list($decoded) ? $decoded : [];
        }

        return $this->decodedEvents;
    }

    /**
     * @return list<string>
     */
    private function payloadCarryingTypes(): array
    {
        return array_values(array_map(
            static fn (CmsEventType $type): string => $type->value,
            array_filter(CmsEventType::cases(), static fn (CmsEventType $type): bool => $type->carriesPayload()),
        ));
    }
}

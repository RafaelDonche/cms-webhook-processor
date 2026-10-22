<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('consumers:token {email : Email of the consumer} {--name=default : Label that identifies the token} {--expires-in-days= : Days until the token expires}')]
#[Description('Issue a bearer token for an API consumer; the token is displayed only once')]
final class IssueConsumerTokenCommand extends Command
{
    public const int MAX_TTL_DAYS = 365;

    private const array ALL_ABILITIES = ['*'];

    public function handle(): int
    {
        $validator = Validator::make([
            'name' => $this->option('name'),
            'expires_in_days' => $this->option('expires-in-days') ?? config()->integer('api.token_ttl_days'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TTL_DAYS],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $consumer = User::query()->where('email', $this->argument('email'))->first();

        if ($consumer === null) {
            $this->components->error('No consumer is registered with that email.');

            return self::FAILURE;
        }

        /** @var array{name: string, expires_in_days: int|string} $options */
        $options = $validator->validated();
        $expiresAt = now()->addDays((int) $options['expires_in_days']);
        $token = $consumer->createToken($options['name'], self::ALL_ABILITIES, $expiresAt);

        $this->components->info(
            "Token issued for [{$consumer->email}] with the [{$consumer->role->value}] role, expiring at {$expiresAt->toIso8601ZuluString()}."
        );
        $this->components->warn('Store it now: it cannot be displayed again.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}

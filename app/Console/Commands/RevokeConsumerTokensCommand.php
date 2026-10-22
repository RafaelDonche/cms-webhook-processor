<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('consumers:revoke {email : Email of the consumer}')]
#[Description('Revoke every token of an API consumer')]
final class RevokeConsumerTokensCommand extends Command
{
    public function handle(): int
    {
        $consumer = User::query()->where('email', $this->argument('email'))->first();

        if ($consumer === null) {
            $this->components->error('No consumer is registered with that email.');

            return self::FAILURE;
        }

        $revoked = $consumer->tokens()->delete();

        $this->components->info("Revoked {$revoked} token(s) of [{$consumer->email}].");

        return self::SUCCESS;
    }
}

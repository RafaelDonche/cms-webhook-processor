<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('consumers:create {name : Display name of the consumer} {email : Unique email that identifies the consumer} {--admin : Grant the administrator role}')]
#[Description('Register an API consumer with the user or administrator role')]
final class CreateConsumerCommand extends Command
{
    public function handle(): int
    {
        $validator = Validator::make($this->arguments(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, email: string} $attributes */
        $attributes = $validator->validated();

        $consumer = new User($attributes);
        $consumer->role = $this->option('admin') ? UserRole::Admin : UserRole::User;
        $consumer->save();

        $this->components->info("Consumer [{$consumer->email}] created with the [{$consumer->role->value}] role.");
        $this->components->bulletList(["Issue its token with: php artisan consumers:token {$consumer->email}"]);

        return self::SUCCESS;
    }
}

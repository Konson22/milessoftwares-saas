<?php

namespace App\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Closure|array<mixed>|string>>
     */
    protected function profileRules(?string $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function emailRules(?string $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            // Case-insensitive, matching the lower(email) unique index.
            function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                $taken = User::query()
                    ->whereRaw('lower(email) = ?', [mb_strtolower((string) $value)])
                    ->when($userId !== null, fn ($query) => $query->whereKeyNot($userId))
                    ->exists();

                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => $attribute]));
                }
            },
        ];
    }
}

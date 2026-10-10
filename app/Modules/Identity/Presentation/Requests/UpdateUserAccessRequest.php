<?php

namespace App\Modules\Identity\Presentation\Requests;

use App\Modules\Identity\Domain\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('identity.manage-users') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required_without:is_active', Rule::enum(UserRole::class)],
            'is_active' => ['required_without:role', 'boolean'],
        ];
    }
}

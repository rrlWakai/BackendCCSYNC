<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', Rule::in([
                Role::ADMINISTRATOR,
                Role::DEPARTMENT_PERSONNEL,
                Role::PROFESSOR,
                Role::STUDENT,
            ])],
            'status' => ['sometimes', 'nullable', Rule::in([
                'active',
                'inactive',
                'suspended',
                'pending_approval',
                'needs_correction',
                'rejected',
            ])],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

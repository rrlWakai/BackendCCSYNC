<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\ApiFormRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;

final class UpdateDepartmentPersonnelProgramAccessRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && $target->hasRole(Role::DEPARTMENT_PERSONNEL)
            && ($this->user()?->can('manageProgramAccess', $target) ?? false);
    }

    public function rules(): array
    {
        return [
            'program_ids' => ['present', 'array', 'max:100'],
            'program_ids.*' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists('programs', 'id')->where('status', 'active'),
            ],
        ];
    }
}

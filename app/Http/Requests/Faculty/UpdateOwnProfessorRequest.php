<?php

namespace App\Http\Requests\Faculty;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOwnProfessorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $professor = $this->user()?->professor;

        return $professor !== null
            && ($this->user()?->can('updateOwn', $professor) ?? false);
    }

    public function rules(): array
    {
        $professor = $this->user()?->professor;
        $programRule = Rule::exists('programs', 'id')->where(
            fn ($query) => $query->where('status', 'active')
                ->when($professor?->program_id !== null, fn ($query) => $query->orWhere('id', $professor->program_id)),
        );

        return [
            'employee_number' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('professors', 'employee_number')->ignore($professor?->id),
            ],
            'program_id' => ['sometimes', 'nullable', 'integer', $programRule],
            'employment_type' => ['sometimes', 'required', Rule::in(['full_time', 'part_time', 'visiting'])],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['prohibited'],
            'role' => ['prohibited'],
            'email' => ['prohibited'],
            'name' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }
}

<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;

final class RejectSchedulingCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}

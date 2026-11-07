<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SchedulingOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => [
                'required',
                'integer',
                Rule::exists('academic_terms', 'id')->where(fn ($query) => $query->whereIn('status', ['active', 'planned'])),
            ],
        ];
    }
}

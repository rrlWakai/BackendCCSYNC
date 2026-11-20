<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;

final class IndexSchedulingPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ];
    }
}

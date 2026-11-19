<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCandidateRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'change_summary' => [
                'required',
                'string',
                'max:1000',
            ],
            'parent_revision_uuid' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('scheduling_candidate_revisions', 'uuid'),
            ],
        ];
    }
}

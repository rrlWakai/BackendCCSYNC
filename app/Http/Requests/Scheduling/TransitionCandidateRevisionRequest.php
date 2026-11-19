<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionCandidateRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_version' => [
                'required',
                'integer',
                'min:1',
            ],
            'to_status' => [
                'required',
                'string',
                Rule::in([
                    'draft',
                    'under_review',
                    'validated',
                    'needs_revision',
                    'superseded',
                    'discarded',
                ]),
            ],
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ];
    }
}

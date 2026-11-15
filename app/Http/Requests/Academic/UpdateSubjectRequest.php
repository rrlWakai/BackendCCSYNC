<?php

namespace App\Http\Requests\Academic;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateSubjectRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('subject')) ?? false;
    }

    public function rules(): array
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('subjects', 'code')->ignore($subject)],
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string'],
            'units' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:999.9'],
            'lecture_hours' => ['required_with:lab_hours', 'numeric', 'gte:0', 'max:999.9'],
            'lab_hours' => ['required_with:lecture_hours', 'numeric', 'gte:0', 'max:999.9'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if (! $this->has('lecture_hours') || ! $this->has('lab_hours')) {
                    return;
                }

                $lectureHours = $this->input('lecture_hours');
                $labHours = $this->input('lab_hours');

                if (! is_numeric($lectureHours) || ! is_numeric($labHours)) {
                    return;
                }

                if ((float) $lectureHours <= 0 && (float) $labHours <= 0) {
                    $validator->errors()->add(
                        'lecture_hours',
                        'At least one of the lecture or laboratory weekly hours must be greater than zero.',
                    );
                }
            },
        ];
    }
}

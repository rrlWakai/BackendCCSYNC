<?php

namespace App\Http\Requests\Faculty;

use App\Models\Professor;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateFacultyQuotaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('weekly_teaching_hours_quota')
            && is_string($this->input('weekly_teaching_hours_quota'))
            && trim($this->input('weekly_teaching_hours_quota')) === '') {
            $this->merge(['weekly_teaching_hours_quota' => null]);
        }
    }

    public function authorize(): bool
    {
        $professor = $this->route('professor');

        return $professor instanceof Professor
            && ($this->user()?->can('manageQuota', $professor) ?? false);
    }

    public function rules(): array
    {
        return [
            'weekly_teaching_hours_quota' => [
                'present',
                'nullable',
                'numeric',
                'min:0',
                'max:999.9',
                'decimal:0,1',
            ],
        ];
    }
}

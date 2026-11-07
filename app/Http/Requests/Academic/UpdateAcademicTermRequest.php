<?php

namespace App\Http\Requests\Academic;

use App\Models\AcademicTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAcademicTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('term')) ?? false;
    }

    public function rules(): array
    {
        /** @var AcademicTerm $term */
        $term = $this->route('term');
        $academicYear = $this->input('academic_year', $term->academic_year);
        $classification = $this->input('term', $term->term);

        return [
            'academic_year' => ['sometimes', 'required', 'string', 'regex:/^\d{4}-\d{4}$/', Rule::unique('academic_terms')->where(fn ($query) => $query->where('term', $classification))->ignore($term)],
            'term' => ['sometimes', 'required', Rule::in(['first', 'second', 'summer']), Rule::unique('academic_terms', 'term')->where(fn ($query) => $query->where('academic_year', $academicYear))->ignore($term)],
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'starts_on' => ['sometimes', 'required', 'date'],
            'ends_on' => ['sometimes', 'required', 'date', 'after_or_equal:starts_on'],
            'is_current' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['planned', 'active', 'completed', 'archived'])],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                /** @var AcademicTerm $term */
                $term = $this->route('term');
                $academicYear = $this->input('academic_year', $term->academic_year);
                $status = $this->input('status', $term->status);
                $isCurrent = $this->boolean('is_current', $term->is_current);

                if (preg_match('/^(\d{4})-(\d{4})$/', (string) $academicYear, $matches) === 1
                    && (int) $matches[2] !== (int) $matches[1] + 1) {
                    $validator->errors()->add('academic_year', 'The second year must immediately follow the first year.');
                }

                if ($isCurrent && $status !== 'active') {
                    $validator->errors()->add('is_current', 'Only an active academic term can be current.');
                }

                $startsOn = $this->input('starts_on', $term->starts_on?->toDateString());
                $endsOn = $this->input('ends_on', $term->ends_on?->toDateString());

                if ($startsOn && $endsOn && strtotime($endsOn) < strtotime($startsOn)) {
                    $validator->errors()->add('ends_on', 'The end date must be on or after the start date.');
                }
            },
        ];
    }
}

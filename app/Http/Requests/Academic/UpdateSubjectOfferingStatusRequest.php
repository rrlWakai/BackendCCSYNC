<?php

namespace App\Http\Requests\Academic;

use App\Models\SubjectOffering;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSubjectOfferingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subjectOffering = $this->route('subjectOffering');

        return $subjectOffering instanceof SubjectOffering
            && ($this->user()?->can('changeStatus', $subjectOffering) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['open', 'closed'])],
        ];
    }
}

<?php

namespace App\Http\Requests\Scheduling;

use App\Http\Requests\ApiFormRequest;

final class SchedulingConfigurationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ];
    }
}

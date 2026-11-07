<?php

namespace App\Http\Requests\Activities;

use App\Http\Requests\ApiFormRequest;
use App\Models\Activity;
use Illuminate\Validation\Rule;

final class IndexActivitiesRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', Rule::in(Activity::CATEGORIES)],
            'status' => ['sometimes', 'nullable', Rule::in(['upcoming', 'ongoing', 'completed', 'cancelled'])],
            'from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

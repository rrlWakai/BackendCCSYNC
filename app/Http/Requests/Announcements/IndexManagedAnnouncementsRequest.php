<?php

namespace App\Http\Requests\Announcements;

use App\Http\Requests\ApiFormRequest;
use App\Models\Announcement;
use Illuminate\Validation\Rule;

final class IndexManagedAnnouncementsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(Announcement::STATUSES)],
            'category' => ['sometimes', 'nullable', Rule::in(Announcement::CATEGORIES)],
            'priority' => ['sometimes', 'nullable', Rule::in(Announcement::PRIORITIES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

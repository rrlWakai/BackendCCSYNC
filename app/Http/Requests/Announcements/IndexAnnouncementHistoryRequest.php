<?php

namespace App\Http\Requests\Announcements;

use App\Http\Requests\ApiFormRequest;

final class IndexAnnouncementHistoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

<?php

namespace App\Http\Requests\Announcements;

use App\Http\Requests\Announcements\Concerns\ValidatesAnnouncementPayload;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAnnouncementRequest extends FormRequest
{
    use ValidatesAnnouncementPayload;

    protected function prepareForValidation(): void
    {
        $this->prepareAnnouncementPayload();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Announcement::class) ?? false;
    }

    public function rules(): array
    {
        return $this->announcementRules(false);
    }
}

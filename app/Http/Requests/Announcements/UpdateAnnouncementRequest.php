<?php

namespace App\Http\Requests\Announcements;

use App\Http\Requests\Announcements\Concerns\ValidatesAnnouncementPayload;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAnnouncementRequest extends FormRequest
{
    use ValidatesAnnouncementPayload;

    protected function prepareForValidation(): void
    {
        $this->prepareAnnouncementPayload();
    }

    public function authorize(): bool
    {
        $announcement = $this->route('announcement');

        return $announcement instanceof Announcement
            && ($this->user()?->can('update', $announcement) ?? false);
    }

    public function rules(): array
    {
        return $this->announcementRules(true);
    }
}

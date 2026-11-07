<?php

namespace App\Http\Requests\Activities;

use App\Http\Requests\Activities\Concerns\ValidatesActivityPayload;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateActivityRequest extends FormRequest
{
    use ValidatesActivityPayload;

    protected function prepareForValidation(): void
    {
        $this->prepareActivityPayload();
    }

    public function authorize(): bool
    {
        $activity = $this->route('activity');

        return $activity instanceof Activity
            && ($this->user()?->can('update', $activity) ?? false);
    }

    public function rules(): array
    {
        return $this->activityRules(true);
    }
}

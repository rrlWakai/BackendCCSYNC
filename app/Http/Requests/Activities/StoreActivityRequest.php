<?php

namespace App\Http\Requests\Activities;

use App\Http\Requests\Activities\Concerns\ValidatesActivityPayload;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;

final class StoreActivityRequest extends FormRequest
{
    use ValidatesActivityPayload;

    protected function prepareForValidation(): void
    {
        $this->prepareActivityPayload();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Activity::class) ?? false;
    }

    public function rules(): array
    {
        return $this->activityRules(false);
    }
}

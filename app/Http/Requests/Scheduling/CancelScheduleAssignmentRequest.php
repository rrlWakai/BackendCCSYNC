<?php

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

final class CancelScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('schedule')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

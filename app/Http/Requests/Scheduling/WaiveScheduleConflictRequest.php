<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ScheduleConflict;
use Illuminate\Foundation\Http\FormRequest;

final class WaiveScheduleConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('waive', ScheduleConflict::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'resolution_notes' => ['required', 'string', 'max:2000'],
        ];
    }
}

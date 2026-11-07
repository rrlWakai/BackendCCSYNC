<?php

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

final class ReleaseRoomAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator', 'department_personnel') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

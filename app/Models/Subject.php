<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'description',
        'units',
        'lecture_hours',
        'lab_hours',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'units' => 'decimal:1',
            'lecture_hours' => 'decimal:1',
            'lab_hours' => 'decimal:1',
        ];
    }

    public function subjectOfferings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }
}

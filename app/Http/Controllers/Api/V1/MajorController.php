<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Models\Major;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class MajorController extends Controller
{
    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Major::class);

        $query = Major::query()->with('program:id,code,name,status');
        if ($request->filled('program_id')) {
            $query->where('program_id', $request->integer('program_id'));
        }

        $result = AcademicDirectory::paginate($query, $request, ['code', 'name'], [
            'status' => 'status',
            'program_id' => 'program_id',
        ]);

        return ApiResponse::success([
            'majors' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Majors loaded.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Major::class);
        $attributes = $this->validatePayload($request);

        $major = Major::create($attributes);

        return ApiResponse::success(['major' => $major->load('program')], 'Major created.', 201);
    }

    public function show(Major $major): JsonResponse
    {
        Gate::authorize('view', Major::class);

        return ApiResponse::success(['major' => $major->load('program')], 'Major loaded.');
    }

    public function update(Request $request, Major $major): JsonResponse
    {
        Gate::authorize('update', Major::class);
        $attributes = $this->validatePayload($request, $major);

        $major->update($attributes);

        return ApiResponse::success(['major' => $major->fresh()->load('program')], 'Major updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Major $major = null): array
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => Str::upper(trim($request->input('code')))]);
        }

        $programId = $request->input('program_id', $major?->program_id);

        return $request->validate([
            'program_id' => [$major === null ? 'required' : 'sometimes', 'integer', Rule::exists('programs', 'id')],
            'code' => [
                $major === null ? 'required' : 'sometimes',
                'string',
                'max:24',
                Rule::unique('majors', 'code')
                    ->where(fn ($query) => $query->where('program_id', $programId))
                    ->ignore($major),
            ],
            'name' => [$major === null ? 'required' : 'sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}

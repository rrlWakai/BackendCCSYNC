<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\IndexAcademicRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\PromotionRun;
use App\Models\PromotionRunItem;
use App\Services\Academic\PromotionService;
use App\Support\AcademicDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class PromotionController extends Controller
{
    private const RELATIONS = [
        'sourceAcademicYear',
        'targetAcademicYear',
        'sourceTerm',
        'targetTerm',
        'items.student.user:id,name,email',
        'items.fromPlacement',
        'items.fromYearLevel',
        'items.toYearLevel',
        'items.major',
        'items.section',
    ];

    public function __construct(private readonly PromotionService $promotions) {}

    public function index(IndexAcademicRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', PromotionRun::class);

        $query = PromotionRun::query()->with(self::RELATIONS);

        foreach (['status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('academic_year_id')) {
            $query->where('source_academic_year_id', $request->integer('academic_year_id'));
        }

        $result = AcademicDirectory::paginate($query->orderByDesc('id'), $request, [], []);

        return ApiResponse::success([
            'promotion_runs' => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Promotion runs loaded.');
    }

    public function due(): JsonResponse
    {
        Gate::authorize('viewAny', PromotionRun::class);

        return ApiResponse::success([
            'due_promotions' => $this->promotions->duePromotions(),
        ], 'Due promotions loaded.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', PromotionRun::class);

        $attributes = $request->validate([
            'source_academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'source_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
            'target_academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'target_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
        ]);

        $run = $this->promotions->prepare(
            AcademicYear::query()->findOrFail($attributes['source_academic_year_id']),
            AcademicTerm::query()->findOrFail($attributes['source_term_id']),
            AcademicYear::query()->findOrFail($attributes['target_academic_year_id']),
            AcademicTerm::query()->findOrFail($attributes['target_term_id']),
            $request->user(),
        );

        return ApiResponse::success(['promotion_run' => $run], 'Promotion run prepared.', 201);
    }

    public function show(PromotionRun $promotionRun): JsonResponse
    {
        Gate::authorize('view', PromotionRun::class);

        return ApiResponse::success([
            'promotion_run' => $promotionRun->load(self::RELATIONS),
        ], 'Promotion run loaded.');
    }

    public function adjustItem(
        Request $request,
        PromotionRun $promotionRun,
        PromotionRunItem $item,
    ): JsonResponse {
        Gate::authorize('update', PromotionRun::class);

        $attributes = $request->validate([
            'to_year_level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('year_levels', 'id')],
            'major_id' => ['sometimes', 'nullable', 'integer', Rule::exists('majors', 'id')],
            'section_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sections', 'id')],
        ]);

        $item = $this->promotions->adjustItem($promotionRun, $item, $attributes);

        return ApiResponse::success(['promotion_run_item' => $item], 'Promotion item updated.');
    }

    public function allocate(PromotionRun $promotionRun): JsonResponse
    {
        Gate::authorize('update', PromotionRun::class);

        $result = $this->promotions->allocate($promotionRun);

        return ApiResponse::success([
            'promotion_run' => $result['run'],
            'allocation' => $result['allocation'],
        ], 'Promotion sections allocated.');
    }

    public function finalize(Request $request, PromotionRun $promotionRun): JsonResponse
    {
        Gate::authorize('finalize', PromotionRun::class);

        $attributes = $request->validate([
            'allow_shortages' => ['sometimes', 'boolean'],
        ]);

        $run = $this->promotions->finalize(
            $promotionRun,
            $request->user(),
            (bool) ($attributes['allow_shortages'] ?? false),
        );

        return ApiResponse::success(['promotion_run' => $run], 'Promotion run finalized.');
    }
}

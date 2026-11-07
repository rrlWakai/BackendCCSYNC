<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class AcademicDirectory
{
    /**
     * @param  list<string>  $searchColumns
     * @param  array<string, string>  $filters
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public static function paginate(
        Builder $query,
        Request $request,
        array $searchColumns,
        array $filters = [],
    ): array {
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search, $searchColumns): void {
                foreach ($searchColumns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $searchQuery->{$method}($column, 'like', '%'.$search.'%');
                }
            });
        }

        foreach ($filters as $parameter => $column) {
            if ($value = $request->query($parameter)) {
                $query->where($column, $value);
            }
        }

        $paginator = $query
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->paginate($request->integer('per_page', 25));

        return [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}

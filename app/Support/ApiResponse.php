<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/** Consistent JSON envelope: { data, message?, meta? } on success, { message, errors?, code? } on failure. */
final class ApiResponse
{
    public static function ok(mixed $data = null, ?string $message = null, int $status = 200, array $extra = []): JsonResponse
    {
        $body = ['data' => $data];
        if ($message !== null) {
            $body['message'] = $message;
        }

        return response()->json($body + $extra, $status);
    }

    /** Same shape as API Resource collections: { data: [...], meta: { current_page, last_page, per_page, total } }. */
    public static function paginated(\Illuminate\Contracts\Pagination\LengthAwarePaginator $page): JsonResponse
    {
        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public static function error(string $message, int $status, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(['message' => $message] + $extra, $status, $headers);
    }
}

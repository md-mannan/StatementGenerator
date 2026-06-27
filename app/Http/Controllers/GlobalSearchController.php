<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $globalSearch): JsonResponse
    {
        return response()->json([
            'results' => $globalSearch->search(
                $request->user(),
                $request->string('q')->toString(),
            ),
        ]);
    }
}

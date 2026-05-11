<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MultiBetBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MultiBetController extends Controller
{
    public function build(Request $request, MultiBetBuilder $builder): JsonResponse
    {
        $maxLegs = min(10, max(2, (int) $request->query('legs', 6)));
        $risk = in_array($request->query('risk'), ['safe', 'balanced', 'value'], true)
            ? $request->query('risk')
            : 'balanced';

        $multi = $builder->build($maxLegs, $risk);

        return response()->json($multi);
    }
}

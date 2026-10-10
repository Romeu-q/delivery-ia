<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $campaigns = Campaign::query()
            ->where('store_id', $request->attributes->get('store')->id)
            ->latest()
            ->limit(20)
            ->get(['id', 'name', 'audience_rule', 'audience_count', 'message_title', 'message_body', 'status', 'created_at']);

        return response()->json(['data' => $campaigns]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\DeliveryService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, DeliveryService $deliveries, SubscriptionService $subscriptions): View
    {
        $subscriptions->expireDue();
        $deliveries->generateForDate(today());
        return view('dashboard', $dashboard->data($this->filters($request)));
    }

    public function summary(Request $request, DashboardService $dashboard): JsonResponse
    {
        $data = $dashboard->data($this->filters($request));
        return response()->json(['cards' => $data['cards'], 'deliveryStats' => $data['deliveryStats'], 'finance' => $data['finance'], 'alerts' => $data['alerts'], 'refreshed_at' => now()->format('h:i A')]);
    }

    private function filters(Request $request): array
    {
        return $request->only(['date', 'from', 'to', 'month', 'year', 'status', 'payment_status', 'meal_type', 'place']);
    }
}

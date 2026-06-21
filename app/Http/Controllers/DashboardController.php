<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\DeliveryService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, DeliveryService $deliveries, SubscriptionService $subscriptions): View
    {
        $subscriptions->expireDue();
        $deliveries->generateForDate(today());
        return view('dashboard', $dashboard->data($request->only(['date', 'month', 'year', 'status', 'payment_status', 'meal_type', 'place'])));
    }
}

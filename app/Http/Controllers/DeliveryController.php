<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Services\DeliveryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    public function index(Request $request, DeliveryService $service): View
    {
        $date = Carbon::parse($request->input('date', today()));
        $service->generateForDate($date);
        $deliveries = Delivery::with(['customer', 'subscription'])->whereDate('delivery_date', $date)
            ->when($request->meal_type, fn ($q, $meal) => $q->where('meal_type', $meal))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->place, fn ($q, $place) => $q->whereHas('customer', fn ($q) => $q->where('place', $place)))
            ->orderByRaw("CASE meal_type WHEN 'breakfast' THEN 1 WHEN 'lunch' THEN 2 ELSE 3 END")->paginate(25)->withQueryString();
        return view('deliveries.index', compact('deliveries', 'date'));
    }

    public function update(Request $request, Delivery $delivery): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:pending,delivered,missed,skipped'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $delivery->update($data + ['delivered_at' => $data['status'] === 'delivered' ? now() : null, 'updated_by' => $request->user()->id]);
        return response()->json(['message' => 'Delivery updated.', 'status' => $delivery->status]);
    }
}

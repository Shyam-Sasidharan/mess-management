<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use App\Services\PaymentService;

class SettingController extends Controller
{
    public function index(): View { return view('settings.index', ['settings' => Setting::all()->keyBy('key')]); }
    public function update(Request $request, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'], 'business_mobile' => ['nullable', 'string', 'max:20'],
            'business_email' => ['nullable', 'email'], 'business_address' => ['nullable', 'string', 'max:2000'],
            'breakfast_price' => ['required', 'numeric', 'min:0'], 'lunch_price' => ['required', 'numeric', 'min:0'], 'dinner_price' => ['required', 'numeric', 'min:0'],
            'one_time_package_price' => ['required', 'numeric', 'min:0'], 'two_time_package_price' => ['required', 'numeric', 'min:0'], 'three_time_package_price' => ['required', 'numeric', 'min:0'],
            'default_subscription_days' => ['required', 'integer', 'between:1,3660'], 'expiry_alert_days' => ['required', 'integer', 'between:1,90'],
            'currency' => ['required', 'string', 'max:10'], 'timezone' => ['required', 'timezone'], 'date_format' => ['required', 'in:d-m-Y,m-d-Y,Y-m-d,d/m/Y'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        if ($request->hasFile('logo')) {
            $old = Setting::value('business_logo'); if ($old) Storage::disk('public')->delete($old);
            $data['business_logo'] = $request->file('logo')->store('branding', 'public');
        }
        unset($data['logo']);
        $groups = ['business_name' => 'business', 'business_mobile' => 'business', 'business_email' => 'business', 'business_address' => 'business', 'business_logo' => 'business', 'breakfast_price' => 'pricing', 'lunch_price' => 'pricing', 'dinner_price' => 'pricing', 'one_time_package_price' => 'pricing', 'two_time_package_price' => 'pricing', 'three_time_package_price' => 'pricing', 'default_subscription_days' => 'subscription', 'expiry_alert_days' => 'subscription', 'currency' => 'system', 'timezone' => 'system', 'date_format' => 'system'];
        foreach ($data as $key => $value) Setting::updateOrCreate(['key' => $key], ['group' => $groups[$key], 'value' => $value, 'type' => is_numeric($value) ? 'decimal' : 'string']);
        $payments->syncOutstandingPackagePrices();
        return back()->with('success', 'Settings saved.');
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = Role::updateOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'permissions' => ['manage-business', 'manage-deliveries'], 'is_active' => true]);
        Role::updateOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'permissions' => ['manage-deliveries'], 'is_active' => true]);

        User::updateOrCreate(['email' => 'admin@goldenmess.test'], [
            'role_id' => $admin->id, 'name' => 'System Admin', 'mobile' => '9999999999',
            'password' => 'ChangeMe@123', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        $categories = ['Grocery','Vegetables','Fish','Chicken','Beef','Rice','Cooking Oil','Milk','Gas Cylinder','Petrol','Electricity Bill','Water Bill','Staff Salary','Vehicle Maintenance','Packaging','Internet','Rent','Miscellaneous'];
        $colors = ['#F4B400','#45A049','#2979FF','#FF7043','#8D6E63','#7E57C2'];
        foreach ($categories as $index => $name) ExpenseCategory::firstOrCreate(['name' => $name], ['color' => $colors[$index % count($colors)], 'is_active' => true]);

        $settings = [
            'business' => ['business_name' => 'Golden Mess', 'business_mobile' => '', 'business_email' => '', 'business_address' => ''],
            'pricing' => ['breakfast_price' => 0, 'lunch_price' => 0, 'dinner_price' => 0, 'one_time_package_price' => 0, 'two_time_package_price' => 0, 'three_time_package_price' => 0],
            'subscription' => ['default_subscription_days' => 30, 'expiry_alert_days' => 7],
            'system' => ['currency' => '₹', 'timezone' => 'Asia/Kolkata', 'date_format' => 'd-m-Y'],
        ];
        foreach ($settings as $group => $values) foreach ($values as $key => $value) Setting::updateOrCreate(['key' => $key], ['group' => $group, 'value' => $value, 'type' => is_numeric($value) ? 'decimal' : 'string']);
    }
}

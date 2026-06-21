<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type'];

    public static function value(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting.{$key}", 3600, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            if (! $setting) return $default;
            return match ($setting->type) {
                'integer' => (int) $setting->value,
                'decimal' => (float) $setting->value,
                'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOL),
                'json' => json_decode($setting->value, true),
                default => $setting->value,
            };
        });
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => Cache::forget("setting.{$setting->key}"));
        static::deleted(fn (Setting $setting) => Cache::forget("setting.{$setting->key}"));
    }
}

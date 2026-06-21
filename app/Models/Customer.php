<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_code', 'name', 'gender', 'age', 'place', 'primary_mobile',
        'secondary_mobile', 'primary_address', 'secondary_address', 'landmark',
        'google_map_url', 'notes', 'food_instructions', 'status', 'paused_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['paused_at' => 'datetime'];
    }

    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
    public function currentSubscription(): HasOne { return $this->hasOne(Subscription::class)->latestOfMany(); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function deliveries(): HasMany { return $this->hasMany(Delivery::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q, string $term) => $q->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('customer_code', 'like', "%{$term}%")
                ->orWhere('primary_mobile', 'like', "%{$term}%")
                ->orWhere('primary_address', 'like', "%{$term}%");
        }));
    }
}

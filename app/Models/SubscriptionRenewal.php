<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionRenewal extends Model
{
    protected $fillable = ['customer_id', 'previous_subscription_id', 'new_subscription_id', 'renewed_on', 'notes', 'created_by'];
    protected function casts(): array { return ['renewed_on' => 'date']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function previousSubscription(): BelongsTo { return $this->belongsTo(Subscription::class, 'previous_subscription_id'); }
    public function newSubscription(): BelongsTo { return $this->belongsTo(Subscription::class, 'new_subscription_id'); }
}

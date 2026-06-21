<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionCompensation extends Model
{
    protected $table = 'subscription_compensations';

    protected $fillable = ['customer_id', 'subscription_id', 'compensation_date', 'compensation_type', 'reason', 'days_added'];
    protected function casts(): array { return ['compensation_date' => 'date']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
}

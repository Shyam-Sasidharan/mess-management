<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubscriptionExpiryNotification extends Notification
{
    use Queueable;
    public function __construct(private Subscription $subscription) {}
    public function via(object $notifiable): array { return ['database']; }
    public function toArray(object $notifiable): array
    {
        return ['title' => 'Subscription expiry alert', 'message' => "{$this->subscription->customer->name}'s subscription expires on {$this->subscription->end_date->format('d M Y')}.", 'customer_id' => $this->subscription->customer_id, 'url' => route('customers.show', $this->subscription->customer_id)];
    }
}

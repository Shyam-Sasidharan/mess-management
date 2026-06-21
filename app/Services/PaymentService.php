<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function create(array $data, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($data, $userId) {
            $subscription = Subscription::findOrFail($data['subscription_id']);
            $paid = (float) $subscription->payments()->sum('amount');
            if ($paid + (float) $data['amount'] > (float) $subscription->amount) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the outstanding subscription balance.']);
            }
            $payment = Payment::create($data + ['customer_id' => $subscription->customer_id, 'receipt_no' => $this->nextReceipt(), 'created_by' => $userId]);
            $this->syncStatus($subscription);
            return $payment;
        });
    }

    public function syncStatus(Subscription $subscription): void
    {
        $paid = (float) $subscription->payments()->sum('amount');
        $status = $paid <= 0 ? 'pending' : ($paid >= (float) $subscription->amount ? 'paid' : 'partial');
        $subscription->update(['payment_status' => $status]);
    }

    public function update(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $oldSubscription = $payment->subscription;
            $newSubscription = Subscription::findOrFail($data['subscription_id']);
            $paidExcludingCurrent = (float) $newSubscription->payments()->where('id', '!=', $payment->id)->sum('amount');
            if ($paidExcludingCurrent + (float) $data['amount'] > (float) $newSubscription->amount) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the outstanding subscription balance.']);
            }
            $payment->update($data + ['customer_id' => $newSubscription->customer_id]);
            if ($oldSubscription && $oldSubscription->isNot($newSubscription)) $this->syncStatus($oldSubscription);
            $this->syncStatus($newSubscription);
            return $payment->refresh();
        });
    }

    private function nextReceipt(): string
    {
        do { $number = 'PAY-'.now()->format('ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT); }
        while (Payment::where('receipt_no', $number)->exists());
        return $number;
    }
}

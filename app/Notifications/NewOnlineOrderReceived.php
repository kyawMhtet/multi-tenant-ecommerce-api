<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Deliberately not ShouldQueue: no queue worker runs in this app today, so
 * a queued notification would silently sit in the jobs table forever — a
 * broken feature that still looks like it works (the order still gets
 * created). Sent synchronously via Notification::send() instead. Adding
 * ShouldQueue later is a one-line change once a worker is guaranteed
 * running, with no call-site changes required.
 */
class NewOnlineOrderReceived extends Notification
{
    use Queueable;

    public function __construct(private readonly Order $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * A readable, stable discriminator stored directly in the `type`
     * column — the default would be this class's full name instead.
     */
    public function databaseType(object $notifiable): string
    {
        return 'new_online_order';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'total' => (float) $this->order->total,
            'currency' => $this->order->currency,
            'customer_name' => $this->order->customer?->name,
            'created_at' => $this->order->created_at?->toIso8601String(),
        ];
    }
}

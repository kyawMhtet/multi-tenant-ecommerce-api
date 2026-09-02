<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Deliberately NOT ShouldQueue: no worker runs today, so a queued notification
 * would sit in the jobs table forever — broken while still looking like it
 * works. Adding ShouldQueue later is a one-line change.
 */
class NewOnlineOrderReceived extends Notification
{
    use Queueable;

    public function __construct(private readonly Order $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** A stable discriminator in `type`; the default would be the FQCN. */
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

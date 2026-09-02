<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

class NotificationService
{
    /**
     * Isolation works differently here: `notifications` has no tenant_id and no
     * BelongsToTenant. It's safe because each row is pinned to one User, and a
     * user belongs to exactly one tenant — safe by construction, not by filter.
     */
    public function listForUser(User $user, array $filters): LengthAwarePaginator
    {
        $query = $filters['unread_only'] ? $user->unreadNotifications() : $user->notifications();

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function unreadCountForUser(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * The ownership check is mandatory: {notification} route-model-binds
     * globally with no scope on the table, so without it a user could mark any
     * tenant's notification read by enumerating uuids.
     *
     * The notifiable_type comparison assumes no Relation::morphMap() exists. If
     * one is ever added it would store a short alias instead of the FQCN and
     * this would fail CLOSED (deny everyone) rather than leak — safe, but it
     * would need updating to the alias.
     */
    public function markAsRead(User $user, DatabaseNotification $notification): void
    {
        abort_unless(
            $notification->notifiable_type === User::class && $notification->notifiable_id === $user->id,
            404,
            'Notification not found.'
        );

        $notification->markAsRead();
    }

    public function markAllAsRead(User $user): void
    {
        $user->unreadNotifications->markAsRead();
    }
}

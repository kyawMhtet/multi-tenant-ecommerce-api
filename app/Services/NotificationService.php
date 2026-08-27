<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

class NotificationService
{
    /**
     * Isolation here works differently from every other tenant-scoped
     * query in this app: the `notifications` table has no tenant_id and
     * no BelongsToTenant scope. It's safe anyway because a notification is
     * pinned to one notifiable_id (a specific User row), and that user
     * belongs to exactly one tenant — $user->notifications() can't
     * surface another tenant's rows by construction, not by an explicit
     * filter.
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
     * The ownership check is mandatory, not optional: {notification}
     * route-model-binds globally (no tenant/user scope at all on the
     * table itself), so without this a user could mark any tenant's
     * notification as read by guessing/enumerating its uuid. 404, not
     * 403, matching the belongs-to-someone-else-looks-like-doesn't-exist
     * convention used everywhere else in this app.
     *
     * notifiable_type === User::class assumes no Relation::morphMap() is
     * registered anywhere (confirmed true today — grep app/ for morphMap).
     * If one's ever added, notifiable_type would start storing the map's
     * short alias instead of the FQCN, and this comparison would fail
     * closed (deny everyone) rather than leak — safe, but would need
     * updating to the alias at that point.
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

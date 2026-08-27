<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(IndexNotificationRequest $request)
    {
        return NotificationResource::collection(
            $this->notificationService->listForUser($request->user(), $request->filters())
        );
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'data' => ['count' => $this->notificationService->unreadCountForUser($request->user())],
        ]);
    }

    public function markAsRead(Request $request, DatabaseNotification $notification)
    {
        $this->notificationService->markAsRead($request->user(), $notification);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function markAllAsRead(Request $request)
    {
        $this->notificationService->markAllAsRead($request->user());

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}

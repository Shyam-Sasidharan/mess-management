<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View { return view('notifications.index', ['notifications' => $request->user()->notifications()->paginate(25)]); }
    public function read(Request $request, string $notification): RedirectResponse { $request->user()->notifications()->findOrFail($notification)->markAsRead(); return back(); }
    public function readAll(Request $request): RedirectResponse { $request->user()->unreadNotifications->markAsRead(); return back()->with('success', 'Notifications marked as read.'); }
}

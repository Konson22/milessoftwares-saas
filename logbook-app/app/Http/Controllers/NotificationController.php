<?php

namespace App\Http\Controllers;

use App\Logbook\Models\Notification;
use App\Logbook\Models\NotificationSetting;
use App\Logbook\Notifications;
use App\Logbook\Push\ExpoPush;
use App\Logbook\PushDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The member's notifications (ADR-0026 §2.8): their own only, in this
 * workspace (tenant scope). Opening one marks it read and goes to its
 * record, where access is checked again.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate([
            'category' => ['nullable', Rule::in(Notification::CATEGORIES)],
            'unread' => ['nullable', 'boolean'],
        ]);
        $me = $this->user()->id;
        $setting = NotificationSetting::query()->where('user_id', $me)->first();

        return Inertia::render('notifications/index', [
            'filters' => ['category' => $data['category'] ?? '', 'unread' => (bool) ($data['unread'] ?? false)],
            'notifications' => Notification::query()->where('user_id', $me)
                ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
                ->when($data['unread'] ?? false, fn ($q) => $q->whereNull('read_at'))
                ->latest('created_at')->paginate(30)->withQueryString()
                ->through(fn (Notification $n) => self::row($n)),
            'email' => collect(Notification::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => ($setting?->email[$c] ?? true) === true]),
            'push' => collect(Notification::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => ($setting?->push[$c] ?? true) === true]),
            'phones' => PushDevices::active($me)->count(),
            'pushEnabled' => ExpoPush::enabled(),
        ]);
    }

    /** The bell: unread count and the latest few. */
    public function recent(): JsonResponse
    {
        $me = $this->user()->id;

        return response()->json([
            'unread' => Notification::query()->where('user_id', $me)->whereNull('read_at')->count(),
            'latest' => Notification::query()->where('user_id', $me)->latest('created_at')->limit(8)->get()->map(fn (Notification $n) => self::row($n))->values(),
        ]);
    }

    public function open(string $notification): RedirectResponse
    {
        $notification = Notification::query()->where('user_id', $this->user()->id)->findOrFail($notification);
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return redirect('/'.trim((string) config('platform-client.path', 'logbook'), '/').'/'.ltrim($notification->link, '/'));
    }

    public function read(string $notification): RedirectResponse
    {
        Notification::query()->where('user_id', $this->user()->id)->whereKey($notification)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    public function readAll(): RedirectResponse
    {
        Notification::query()->where('user_id', $this->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked read.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (Notification::CATEGORIES as $category) {
            $rules["email.{$category}"] = ['required', 'boolean'];
            $rules["push.{$category}"] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $values = ['email' => array_map('boolval', $data['email'])];
        if (isset($data['push'])) {
            $values['push'] = array_map('boolval', $data['push']);
        }
        NotificationSetting::query()->updateOrCreate(['user_id' => $this->user()->id], $values);

        return back()->with('success', 'Notification choices saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'category' => $notification->category,
            'event' => $notification->event,
            'title' => $notification->title,
            'body' => $notification->body,
            'link' => $notification->link,
            'subjectType' => $notification->subject_type,
            'subjectId' => $notification->subject_id,
            'read' => $notification->read_at !== null,
            'at' => self::time($notification->created_at),
            // Where the phone opens it (ADR-0027 §2.5).
            'route' => Notifications::route($notification),
        ];
    }
}

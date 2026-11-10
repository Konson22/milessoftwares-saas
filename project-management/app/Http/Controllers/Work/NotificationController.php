<?php

namespace App\Http\Controllers\Work;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Someone's own notifications in this organization. Nobody else's can be
 * read or changed: another person's look unknown.
 */
class NotificationController extends Controller
{
    public const SHOWN = 100;

    public function index(Request $request): Response
    {
        $notifications = UserNotification::query()->where('user_id', $this->member($request)->id)
            ->latest('created_at')->orderByDesc('id')->limit(self::SHOWN)->get();

        $user = $request->user();

        return Inertia::render('portal/notifications', [
            'emailAlerts' => $user instanceof User && $user->email_alerts,
            'notifications' => $notifications->map(fn (UserNotification $notification) => [
                'id' => $notification->id,
                'kind' => $notification->kind,
                'title' => $notification->title,
                'excerpt' => $notification->excerpt,
                'at' => $notification->created_at->toIso8601String(),
                'read' => $notification->read_at !== null,
            ]),
        ]);
    }

    /**
     * Marks it read and goes to its task, or the page it is about.
     */
    public function open(Request $request, UserNotification $notification): SymfonyResponse
    {
        abort_unless($notification->user_id === $this->member($request)->id, 404);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        $path = match (true) {
            $notification->task_id === null => (string) $notification->url,
            $notification->project_id === null => route('work.tasks.show', $notification->task_id, false),
            default => route('projects.tasks.show', ['project' => $notification->project_id, 'task' => $notification->task_id], false),
        };

        if ($notification->task_id === null || $notification->project_id === null) {
            return redirect($path);
        }

        return to_route('projects.tasks.show', ['project' => $notification->project_id, 'task' => $notification->task_id]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        UserNotification::query()->where('user_id', $this->member($request)->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    /**
     * Whether alerts also come by email (the person's own choice, in every
     * organization they open Projects in).
     */
    public function emailAlerts(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email_alerts' => ['required', 'boolean']]);
        $user = $this->member($request);
        $user->forceFill(['email_alerts' => (bool) $validated['email_alerts']])->save();
        Audit::record('user.email_alerts_changed', $user, ['on' => $user->email_alerts], actor: $user);

        return back()->with('success', $user->email_alerts ? 'Email alerts are on.' : 'Email alerts are off.');
    }
}

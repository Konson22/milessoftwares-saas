<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Audit\AuditLogger;
use App\Modules\Marketing\Models\ContactMessage;
use App\Modules\Marketing\Models\DemoRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Demo requests and contact messages from the public site. Spam is hidden
 * unless asked for, and can be restored if the checks were wrong.
 */
class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'open');

        $filter = fn ($query) => match ($status) {
            'spam' => $query->where('status', 'spam'),
            'closed' => $query->where('status', 'closed'),
            default => $query->whereIn('status', ['new', 'in_progress']),
        };

        return Inertia::render('admin/leads/index', [
            'status' => $status,
            'demoRequests' => $filter(DemoRequest::query()->with('application:id,name'))->latest()->limit(100)->get()->map(fn (DemoRequest $lead) => [
                ...$this->present($lead),
                'product' => $lead->application?->name,
                'preferredContactTime' => $lead->preferred_contact_time,
            ])->all(),
            'contactMessages' => $filter(ContactMessage::query())->latest()->limit(100)->get()->map(fn (ContactMessage $lead) => [
                ...$this->present($lead),
                'subject' => $lead->subject,
            ])->all(),
        ]);
    }

    public function update(Request $request, string $type, string $lead, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['new', 'in_progress', 'closed', 'spam'])]]);

        $record = match ($type) {
            'demo' => DemoRequest::query()->findOrFail($lead),
            'contact' => ContactMessage::query()->findOrFail($lead),
            default => abort(404),
        };

        $record->forceFill(['status' => $validated['status']])->save();

        $staff = $request->attributes->get('staff');
        $audit->record('lead.status_changed', $record, ['type' => $type, 'status' => $validated['status']], actor: $staff instanceof PlatformStaff ? $staff->user : null, actorType: 'staff');

        return back();
    }

    /**
     * @return array{id: string, name: string, email: string, company: string|null, phone: string|null, message: string|null, status: string, receivedAt: string|null}
     */
    private function present(DemoRequest|ContactMessage $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'email' => $lead->email,
            'company' => $lead->company,
            'phone' => $lead->phone,
            'message' => $lead->message,
            'status' => $lead->status,
            'receivedAt' => $lead->created_at?->toIso8601String(),
        ];
    }
}

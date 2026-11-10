<?php

namespace App\Http\Controllers\Work;

use App\Actions\ListStatuses;
use App\Http\Controllers\Controller;
use App\Models\ListStatus;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A list's own statuses. The rules are in App\Actions\ListStatuses.
 */
class ListStatusController extends Controller
{
    public function store(Request $request, TaskList $list): RedirectResponse
    {
        $validated = $this->details($request, $list);

        $status = ListStatuses::add($list, $validated['name'], $validated['category'], $this->member($request));

        return back()->with('success', "{$status->name} added.");
    }

    public function update(Request $request, TaskList $list, ListStatus $status): RedirectResponse
    {
        $validated = $this->details($request, $list, $status);

        ListStatuses::update($list, $status, $validated['name'], $validated['category'], $this->member($request));

        return back()->with('success', "{$validated['name']} saved.");
    }

    public function move(Request $request, TaskList $list, ListStatus $status): RedirectResponse
    {
        $validated = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        ListStatuses::move($list, $status, $validated['direction'] === 'up');

        return back();
    }

    public function destroy(Request $request, TaskList $list, ListStatus $status): RedirectResponse
    {
        ListStatuses::remove($list, $status, $this->member($request));

        return back()->with('success', "{$status->name} removed. Its tasks keep their place.");
    }

    /**
     * @return array{name: string, category: string}
     */
    private function details(Request $request, TaskList $list, ?ListStatus $status = null): array
    {
        $request->merge(['name' => trim($request->string('name')->toString())]);

        /** @var array{name: string, category: string} */
        return $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('list_statuses', 'name')->where('list_id', $list->id)->ignore($status?->id)],
            'category' => ['required', Rule::in(Task::STATUSES)],
        ], ['name.unique' => 'This list already has a status with that name.']);
    }
}

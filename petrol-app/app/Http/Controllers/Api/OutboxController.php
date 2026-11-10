<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSubmission;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\Station;
use App\Models\User;
use App\Petrol\Currencies;
use App\Petrol\OfflineInbox;
use App\Support\CurrentBranch;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Applies a device submission once. A repeated id returns the first result.
 * A sale against a shift that is no longer open is 422 and is not stored.
 */
class OutboxController extends Controller
{
    public function kit(): JsonResponse
    {
        $branchId = app(CurrentBranch::class)->id();

        return response()->json([
            'branch_id' => $branchId,
            'currency' => Money::currency(),
            'currencies' => app(Currencies::class)->accepted($branchId),
            'rates' => ExchangeRate::query()->where('branch_id', $branchId)->whereNull('effective_to')->get(['base_currency', 'quote_currency', 'rate_minor', 'effective_from']),
            'methods' => PaymentMethod::query()->where('branch_id', $branchId)->where('active', true)->get(['id', 'name', 'kind']),
            'customers' => Customer::query()->where('active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, OfflineInbox $inbox): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['sale.record', 'cash.collect', 'expense.record', 'fuel.receive', 'shift.open', 'cash.remit'])],
            'payload' => ['required', 'array'],
        ]);

        $member = $request->user();
        abort_unless($member instanceof User, 401);
        $branchId = app(CurrentBranch::class)->id();

        $existing = AppSubmission::query()->whereKey($validated['id'])->first();
        if ($existing !== null) {
            return $this->replay($existing, $member, $branchId, $validated['kind']);
        }

        $access = app(CurrentAccess::class);
        $permission = $inbox->permission($validated['kind']);
        abort_unless($permission !== null && ($access->get()?->allowsIn($permission, branchId: $branchId) ?? false), 403);

        $station = Station::query()->where('branch_id', $branchId)->where('active', true)->first();
        abort_if($station === null, 404);

        $result = $inbox->apply($validated['kind'], $validated['id'], $validated['payload'], $station, $member, $branchId);

        try {
            AppSubmission::query()->create([
                'id' => $validated['id'],
                'branch_id' => $branchId,
                'user_id' => $member->id,
                'kind' => $validated['kind'],
                'result' => $result,
            ]);
        } catch (UniqueConstraintViolationException) {
            $stored = AppSubmission::query()->whereKey($validated['id'])->firstOrFail();

            return $this->replay($stored, $member, $branchId, $validated['kind']);
        }

        return response()->json($result);
    }

    /**
     * Only the device session that made a submission gets its result back.
     */
    private function replay(AppSubmission $submission, User $member, string $branchId, string $kind): JsonResponse
    {
        abort_unless(
            $submission->user_id === $member->id && $submission->branch_id === $branchId && $submission->kind === $kind,
            409,
            'This submission id was already used for something else.',
        );

        return response()->json($submission->result);
    }
}

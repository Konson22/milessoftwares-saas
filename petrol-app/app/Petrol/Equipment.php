<?php

namespace App\Petrol;

use App\Models\Nozzle;
use App\Models\NozzleConnection;
use App\Models\Pump;
use App\Models\Tank;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pumps, nozzles, and which tank a nozzle draws from.
 */
class Equipment
{
    public function __construct(private readonly Audit $audit) {}

    public function connect(Nozzle $nozzle, Tank $tank, User $by): NozzleConnection
    {
        return DB::transaction(function () use ($nozzle, $tank, $by) {
            if ($tank->branch_id !== $nozzle->branch_id || $tank->fuel_product_id !== $nozzle->fuel_product_id) {
                throw ValidationException::withMessages(['tank_id' => 'The tank must be at this station and hold the nozzle\'s fuel.']);
            }

            if ($tank->status !== Tank::ACTIVE || $nozzle->status !== Nozzle::ACTIVE) {
                throw ValidationException::withMessages(['tank_id' => 'The tank and the nozzle must be active.']);
            }

            NozzleConnection::query()
                ->where('nozzle_id', $nozzle->id)
                ->whereNull('disconnected_at')
                ->update(['disconnected_at' => now()]);

            $connection = NozzleConnection::query()->create([
                'nozzle_id' => $nozzle->id,
                'tank_id' => $tank->id,
                'connected_at' => now(),
            ]);

            $this->audit->record($by, 'nozzle.connected', $connection, null, [
                'nozzle_id' => $nozzle->id,
                'tank_id' => $tank->id,
            ], $nozzle->branch_id);

            return $connection;
        });
    }

    public function setPumpStatus(Pump $pump, string $status, User $by): void
    {
        $before = $pump->status;
        $pump->forceFill(['status' => $status])->save();
        $this->audit->record($by, 'pump.status', $pump, ['status' => $before], ['status' => $status], $pump->branch_id);
    }
}

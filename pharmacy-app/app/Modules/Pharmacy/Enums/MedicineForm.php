<?php

namespace App\Modules\Pharmacy\Enums;

enum MedicineForm: string
{
    case Tablet = 'tablet';
    case Capsule = 'capsule';
    case Syrup = 'syrup';
    case Suspension = 'suspension';
    case Injection = 'injection';
    case Cream = 'cream';
    case Ointment = 'ointment';
    case Drops = 'drops';
    case Inhaler = 'inhaler';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

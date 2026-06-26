<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pole extends Model
{
    use SoftDeletes;

    // Standard pole cable attachment positions in PH telco infrastructure
    const STANDARD_SLOTS = ['C1', 'C2', 'C3', 'C4', 'C5', 'DA'];

    protected $fillable = [
        'pole_code', 'is_manual',
        'lat', 'lng',
        'skycable_status', 'skycable_cleared_at',
        'globe_status', 'globe_cleared_at',
    ];

    protected $casts = [
        'is_manual'           => 'boolean',
        'skycable_cleared_at' => 'datetime',
        'globe_cleared_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (Pole $pole) {
            $now   = now();
            $slots = [];
            foreach (self::STANDARD_SLOTS as $label) {
                $slots[] = [
                    'pole_id'     => $pole->id,
                    'slot_label'  => $label,
                    'occupied_by' => 'free',
                    'status'      => 'free',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
            PoleCableSlot::insert($slots);
        });
    }

    public function barangay()
    {
        return $this->belongsTo(PsgcBarangay::class, 'barangay_code', 'code');
    }

    public function cableSlots()
    {
        return $this->hasMany(PoleCableSlot::class)->orderByRaw("FIELD(slot_label,'C1','C2','C3','C4','C5','DA')");
    }

    public function napBoxes()
    {
        return $this->hasMany(GlobeNapBox::class);
    }
}

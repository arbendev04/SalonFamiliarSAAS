<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AttendanceDeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical marking device (biometric reader, kiosk, etc.) associated to a
 * branch. No CRUD/UI exists for this model yet — there is no real device to
 * manage until Fase 12 (see .ai/07-ATTENDANCE.md); it exists now purely so
 * attendance_events.device_id can reference it (YAGNI otherwise).
 */
class AttendanceDevice extends Model
{
    /** @use HasFactory<AttendanceDeviceFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'provider',
        'external_device_id',
        'status',
        'last_heartbeat_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

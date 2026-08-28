<?php

namespace App\Http\Requests;

use App\Services\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attendance.record');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', Rule::in(['clock_in', 'break_start', 'break_end', 'clock_out'])],
            'event_datetime' => ['required', 'date'],
            // MVP scope restriction (.ai/25-MVP-SCOPE.md): only web/manual
            // sources are supported this phase. Biometric/mobile/QR/API/
            // device sources are reserved for later phases and must be
            // rejected here, not silently accepted.
            'source' => ['required', 'string', Rule::in(['web', 'manual'])],
            'device_id' => [
                'nullable',
                'uuid',
                Rule::exists('attendance_devices', 'id')->where('company_id', app(CurrentCompany::class)->id()),
            ],
        ];
    }
}

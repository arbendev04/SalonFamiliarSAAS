<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AttendanceAdjustmentController from '@/actions/App/Http/Controllers/AttendanceAdjustmentController';
import AttendanceEventController from '@/actions/App/Http/Controllers/AttendanceEventController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/employees';

type EmployeeDetail = {
    id: string;
    full_name: string;
};

type AttendanceEventRow = {
    id: string;
    event_type: string;
    event_datetime: string;
    source: string;
    anomaly: string | null;
};

type AttendanceAdjustmentRow = {
    id: string;
    type: string;
    original_event_id: string | null;
    corrected_value: Record<string, unknown>;
    reason: string;
    status: string;
    requested_by: string | null;
    approved_by: string | null;
};

const props = defineProps<{
    employee: EmployeeDetail;
    events: AttendanceEventRow[];
    adjustments: AttendanceAdjustmentRow[];
    canRecordAttendance: boolean;
    canRequestAdjustment: boolean;
    canApproveAdjustments: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Empleados', href: index() },
        ],
    },
});

const eventTypeLabels: Record<string, string> = {
    clock_in: 'Entrada',
    break_start: 'Inicio de descanso',
    break_end: 'Fin de descanso',
    clock_out: 'Salida',
};

const sourceLabels: Record<string, string> = {
    web: 'Web',
    manual: 'Manual',
};

const adjustmentTypeLabels: Record<string, string> = {
    modify: 'Modificar',
    add: 'Agregar',
    invalidate: 'Invalidar',
};

const adjustmentStatusLabels: Record<string, string> = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado',
};

function nowForDatetimeLocalInput(): string {
    const now = new Date();
    const offsetMs = now.getTimezoneOffset() * 60 * 1000;

    return new Date(now.getTime() - offsetMs).toISOString().slice(0, 16);
}

const defaultEventDatetime = nowForDatetimeLocalInput();

const adjustmentType = ref('modify');

function eventLabel(eventId: string): string {
    const event = props.events.find((candidate) => candidate.id === eventId);

    if (!event) {
        return eventId;
    }

    return `${eventTypeLabels[event.event_type] ?? event.event_type} — ${event.event_datetime}`;
}
</script>

<template>
    <Head :title="`Asistencia de ${employee.full_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Asistencia de ${employee.full_name}`"
            description="Marcaciones registradas"
        />

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Tipo</th>
                        <th class="p-3 font-medium">Fecha y hora</th>
                        <th class="p-3 font-medium">Fuente</th>
                        <th class="p-3 font-medium">Anomalía</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="event in events"
                        :key="event.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">
                            {{ eventTypeLabels[event.event_type] ?? event.event_type }}
                        </td>
                        <td class="p-3">{{ event.event_datetime }}</td>
                        <td class="p-3">
                            {{ sourceLabels[event.source] ?? event.source }}
                        </td>
                        <td class="p-3">
                            <span
                                v-if="event.anomaly"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                            >
                                Fuera de secuencia
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                    <tr v-if="events.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="4">
                            Todavía no hay marcaciones registradas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canRecordAttendance"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Fichar" />

            <Form
                v-bind="AttendanceEventController.store.form(employee.id)"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="event_type">Tipo de marcación</Label>
                    <select
                        id="event_type"
                        name="event_type"
                        required
                        class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                    >
                        <option value="clock_in">Entrada</option>
                        <option value="break_start">Inicio de descanso</option>
                        <option value="break_end">Fin de descanso</option>
                        <option value="clock_out">Salida</option>
                    </select>
                    <InputError :message="errors.event_type" />
                </div>

                <div class="grid gap-2">
                    <Label for="event_datetime">Fecha y hora</Label>
                    <Input
                        id="event_datetime"
                        type="datetime-local"
                        name="event_datetime"
                        :default-value="defaultEventDatetime"
                        required
                    />
                    <InputError :message="errors.event_datetime" />
                </div>

                <div class="grid gap-2">
                    <Label for="source">Fuente</Label>
                    <select
                        id="source"
                        name="source"
                        required
                        class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                    >
                        <option value="web">Web</option>
                        <option value="manual">Manual</option>
                    </select>
                    <InputError :message="errors.source" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Fichar
                </Button>
            </Form>
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Tipo</th>
                        <th class="p-3 font-medium">Motivo</th>
                        <th class="p-3 font-medium">Estado</th>
                        <th class="p-3 font-medium">Solicitado por</th>
                        <th class="p-3 font-medium">Aprobado por</th>
                        <th
                            v-if="canApproveAdjustments"
                            class="p-3 font-medium"
                        >
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="adjustment in adjustments"
                        :key="adjustment.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">
                            {{
                                adjustmentTypeLabels[adjustment.type] ??
                                adjustment.type
                            }}
                        </td>
                        <td class="p-3">{{ adjustment.reason }}</td>
                        <td class="p-3">
                            {{
                                adjustmentStatusLabels[adjustment.status] ??
                                adjustment.status
                            }}
                        </td>
                        <td class="p-3">
                            {{ adjustment.requested_by ?? '—' }}
                        </td>
                        <td class="p-3">
                            {{ adjustment.approved_by ?? '—' }}
                        </td>
                        <td
                            v-if="canApproveAdjustments"
                            class="p-3"
                        >
                            <div
                                v-if="adjustment.status === 'pending'"
                                class="flex gap-2"
                            >
                                <Form
                                    v-bind="
                                        AttendanceAdjustmentController.approve.form(
                                            adjustment.id,
                                        )
                                    "
                                    v-slot="{ processing: approving }"
                                >
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="approving"
                                    >
                                        <Spinner v-if="approving" />
                                        Aprobar
                                    </Button>
                                </Form>
                                <Form
                                    v-bind="
                                        AttendanceAdjustmentController.reject.form(
                                            adjustment.id,
                                        )
                                    "
                                    v-slot="{ processing: rejecting }"
                                >
                                    <Button
                                        type="submit"
                                        variant="secondary"
                                        size="sm"
                                        :disabled="rejecting"
                                    >
                                        <Spinner v-if="rejecting" />
                                        Rechazar
                                    </Button>
                                </Form>
                            </div>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                    <tr v-if="adjustments.length === 0">
                        <td
                            class="p-3 text-muted-foreground"
                            :colspan="canApproveAdjustments ? 6 : 5"
                        >
                            Todavía no hay ajustes solicitados.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canRequestAdjustment"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Solicitar ajuste" />

            <Form
                v-bind="
                    AttendanceAdjustmentController.store.form(employee.id)
                "
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="adjustment_type">Tipo de ajuste</Label>
                    <select
                        id="adjustment_type"
                        name="type"
                        v-model="adjustmentType"
                        required
                        class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                    >
                        <option value="modify">Modificar</option>
                        <option value="add">Agregar</option>
                        <option value="invalidate">Invalidar</option>
                    </select>
                    <InputError :message="errors.type" />
                </div>

                <div
                    v-if="adjustmentType === 'modify' || adjustmentType === 'invalidate'"
                    class="grid gap-2"
                >
                    <Label for="original_event_id">Evento original</Label>
                    <select
                        id="original_event_id"
                        name="original_event_id"
                        required
                        class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                    >
                        <option
                            v-for="event in events"
                            :key="event.id"
                            :value="event.id"
                        >
                            {{ eventLabel(event.id) }}
                        </option>
                    </select>
                    <InputError :message="errors.original_event_id" />
                </div>

                <template v-if="adjustmentType === 'add'">
                    <div class="grid gap-2">
                        <Label for="corrected_event_type">
                            Tipo de marcación a agregar
                        </Label>
                        <select
                            id="corrected_event_type"
                            name="corrected_value[event_type]"
                            required
                            class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                        >
                            <option value="clock_in">Entrada</option>
                            <option value="break_start">
                                Inicio de descanso
                            </option>
                            <option value="break_end">Fin de descanso</option>
                            <option value="clock_out">Salida</option>
                        </select>
                        <InputError
                            :message="errors['corrected_value.event_type']"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="corrected_event_datetime">
                            Fecha y hora a agregar
                        </Label>
                        <Input
                            id="corrected_event_datetime"
                            type="datetime-local"
                            name="corrected_value[event_datetime]"
                            required
                        />
                        <InputError
                            :message="
                                errors['corrected_value.event_datetime']
                            "
                        />
                    </div>
                </template>

                <div
                    v-if="adjustmentType === 'modify'"
                    class="grid gap-2"
                >
                    <Label for="corrected_event_datetime_modify">
                        Fecha y hora correcta
                    </Label>
                    <Input
                        id="corrected_event_datetime_modify"
                        type="datetime-local"
                        name="corrected_value[event_datetime]"
                        required
                    />
                    <InputError
                        :message="errors['corrected_value.event_datetime']"
                    />
                </div>

                <div
                    v-if="adjustmentType === 'invalidate'"
                    class="grid gap-2"
                >
                    <Label for="corrected_value_reason_code">
                        Motivo de invalidación
                    </Label>
                    <Input
                        id="corrected_value_reason_code"
                        name="corrected_value[reason_code]"
                        required
                        placeholder="Ej: marcación duplicada por error"
                    />
                    <InputError
                        :message="errors['corrected_value.reason_code']"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="reason">Motivo del ajuste</Label>
                    <Input
                        id="reason"
                        name="reason"
                        required
                        maxlength="500"
                        placeholder="Ej: olvidó marcar la salida"
                    />
                    <InputError :message="errors.reason" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Solicitar ajuste
                </Button>
            </Form>
        </div>
    </div>
</template>

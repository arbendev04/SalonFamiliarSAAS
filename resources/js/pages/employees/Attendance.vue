<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
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

defineProps<{
    employee: EmployeeDetail;
    events: AttendanceEventRow[];
    canRecordAttendance: boolean;
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

function nowForDatetimeLocalInput(): string {
    const now = new Date();
    const offsetMs = now.getTimezoneOffset() * 60 * 1000;
    return new Date(now.getTime() - offsetMs).toISOString().slice(0, 16);
}

const defaultEventDatetime = nowForDatetimeLocalInput();
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
    </div>
</template>

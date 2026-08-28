<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AttendanceRecordController from '@/actions/App/Http/Controllers/AttendanceRecordController';
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

type AttendanceRecordRow = {
    id: string;
    date: string;
    planned_json: Record<string, unknown>;
    worked_json: Record<string, unknown>;
    ordinary_minutes: number;
    overtime_candidate_minutes: number;
    missing_minutes: number;
    calculated_at: string | null;
};

defineProps<{
    employee: EmployeeDetail;
    records: AttendanceRecordRow[];
    canCalculate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Empleados', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="`Cálculo de tiempo de ${employee.full_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Cálculo de tiempo de ${employee.full_name}`"
            description="Registros calculados de asistencia"
        />

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Fecha</th>
                        <th class="p-3 font-medium">Minutos ordinarios</th>
                        <th class="p-3 font-medium">
                            Minutos extra candidatos
                        </th>
                        <th class="p-3 font-medium">Minutos faltantes</th>
                        <th class="p-3 font-medium">Fecha de cálculo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="record in records"
                        :key="record.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">{{ record.date }}</td>
                        <td class="p-3">{{ record.ordinary_minutes }}</td>
                        <td class="p-3">
                            {{ record.overtime_candidate_minutes }}
                        </td>
                        <td class="p-3">{{ record.missing_minutes }}</td>
                        <td class="p-3">
                            {{ record.calculated_at ?? '—' }}
                        </td>
                    </tr>
                    <tr v-if="records.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="5">
                            Todavía no hay registros calculados.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canCalculate"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Recalcular" />

            <Form
                v-bind="
                    AttendanceRecordController.recalculate.form(employee.id)
                "
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="start_date">Fecha inicial</Label>
                    <Input
                        id="start_date"
                        type="date"
                        name="start_date"
                        required
                    />
                    <InputError :message="errors.start_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="end_date">Fecha final</Label>
                    <Input id="end_date" type="date" name="end_date" required />
                    <InputError :message="errors.end_date" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Recalcular
                </Button>
            </Form>
        </div>
    </div>
</template>

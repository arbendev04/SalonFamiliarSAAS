<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import EmployeeScheduleController from '@/actions/App/Http/Controllers/EmployeeScheduleController';
import ShiftController from '@/actions/App/Http/Controllers/ShiftController';
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

type ShiftRow = {
    id: string;
    date: string;
    start_datetime: string;
    end_datetime: string;
    crosses_midnight: boolean;
    source: string;
};

type TemplateOption = {
    id: string;
    name: string;
};

defineProps<{
    employee: EmployeeDetail;
    shifts: ShiftRow[];
    templates: TemplateOption[];
    canManageSchedules: boolean;
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
    <Head :title="`Turnos de ${employee.full_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Turnos de ${employee.full_name}`"
            description="Jornada asignada y turnos generados"
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
                        <th class="p-3 font-medium">Inicio</th>
                        <th class="p-3 font-medium">Fin</th>
                        <th class="p-3 font-medium">Nocturno</th>
                        <th class="p-3 font-medium">Origen</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="shift in shifts"
                        :key="shift.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">{{ shift.date }}</td>
                        <td class="p-3">{{ shift.start_datetime }}</td>
                        <td class="p-3">{{ shift.end_datetime }}</td>
                        <td class="p-3">
                            {{ shift.crosses_midnight ? 'Sí' : 'No' }}
                        </td>
                        <td class="p-3">
                            {{
                                shift.source === 'template'
                                    ? 'Plantilla'
                                    : 'Manual'
                            }}
                        </td>
                    </tr>
                    <tr v-if="shifts.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="5">
                            Todavía no hay turnos generados.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManageSchedules"
            class="grid max-w-3xl gap-6 md:grid-cols-2"
        >
            <div
                class="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <Heading variant="small" title="Asignar jornada" />

                <Form
                    v-bind="EmployeeScheduleController.store.form(employee.id)"
                    reset-on-success
                    v-slot="{ errors, processing }"
                    class="grid gap-4"
                >
                    <div class="grid gap-2">
                        <Label for="template_id">Plantilla</Label>
                        <select
                            id="template_id"
                            name="template_id"
                            required
                            class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                        >
                            <option
                                v-for="template in templates"
                                :key="template.id"
                                :value="template.id"
                            >
                                {{ template.name }}
                            </option>
                        </select>
                        <InputError :message="errors.template_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="effective_from">Vigente desde</Label>
                        <Input
                            id="effective_from"
                            type="date"
                            name="effective_from"
                            required
                        />
                        <InputError :message="errors.effective_from" />
                    </div>

                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Asignar jornada
                    </Button>
                </Form>
            </div>

            <div
                class="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <Heading variant="small" title="Generar turnos" />

                <Form
                    v-bind="ShiftController.generate.form(employee.id)"
                    reset-on-success
                    v-slot="{ errors, processing }"
                    class="grid gap-4"
                >
                    <div class="grid gap-2">
                        <Label for="start_date">Desde</Label>
                        <Input
                            id="start_date"
                            type="date"
                            name="start_date"
                            required
                        />
                        <InputError :message="errors.start_date" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="end_date">Hasta</Label>
                        <Input
                            id="end_date"
                            type="date"
                            name="end_date"
                            required
                        />
                        <InputError :message="errors.end_date" />
                    </div>

                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Generar turnos
                    </Button>
                </Form>
            </div>
        </div>
    </div>
</template>

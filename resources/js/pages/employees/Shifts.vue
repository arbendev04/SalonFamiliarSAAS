<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import EmployeeScheduleController from '@/actions/App/Http/Controllers/EmployeeScheduleController';
import ShiftAssignmentController from '@/actions/App/Http/Controllers/ShiftAssignmentController';
import ShiftBreakController from '@/actions/App/Http/Controllers/ShiftBreakController';
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

type EmployeeOption = {
    id: string;
    full_name: string;
};

const props = defineProps<{
    employee: EmployeeDetail;
    shifts: ShiftRow[];
    templates: TemplateOption[];
    employees: EmployeeOption[];
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

const activeBreakFormShiftId = ref<string | null>(null);
const activeReassignFormShiftId = ref<string | null>(null);

function toggleBreakForm(shiftId: string) {
    activeReassignFormShiftId.value = null;
    activeBreakFormShiftId.value =
        activeBreakFormShiftId.value === shiftId ? null : shiftId;
}

function toggleReassignForm(shiftId: string) {
    activeBreakFormShiftId.value = null;
    activeReassignFormShiftId.value =
        activeReassignFormShiftId.value === shiftId ? null : shiftId;
}

function closeInlineForms() {
    activeBreakFormShiftId.value = null;
    activeReassignFormShiftId.value = null;
}

function otherEmployees(currentEmployeeId: string) {
    return props.employees.filter((option) => option.id !== currentEmployeeId);
}
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
                        <th v-if="canManageSchedules" class="p-3 font-medium">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="shift in shifts" :key="shift.id">
                        <tr
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
                            <td v-if="canManageSchedules" class="p-3">
                                <div class="flex gap-2">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        @click="toggleBreakForm(shift.id)"
                                    >
                                        Agregar descanso
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        @click="toggleReassignForm(shift.id)"
                                    >
                                        Reasignar
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr
                            v-if="
                                canManageSchedules &&
                                activeBreakFormShiftId === shift.id
                            "
                            class="border-b border-sidebar-border/40 bg-muted/30 last:border-0 dark:border-sidebar-border/40"
                        >
                            <td colspan="6" class="p-3">
                                <Form
                                    v-bind="
                                        ShiftBreakController.store.form(
                                            shift.id,
                                        )
                                    "
                                    reset-on-success
                                    @success="closeInlineForms"
                                    v-slot="{ errors, processing }"
                                    class="grid max-w-xl gap-4 sm:grid-cols-3"
                                >
                                    <div class="grid gap-2">
                                        <Label
                                            :for="`planned_start-${shift.id}`"
                                        >
                                            Inicio del descanso
                                        </Label>
                                        <Input
                                            :id="`planned_start-${shift.id}`"
                                            type="datetime-local"
                                            name="planned_start"
                                            required
                                        />
                                        <InputError
                                            :message="errors.planned_start"
                                        />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label :for="`planned_end-${shift.id}`">
                                            Fin del descanso
                                        </Label>
                                        <Input
                                            :id="`planned_end-${shift.id}`"
                                            type="datetime-local"
                                            name="planned_end"
                                            required
                                        />
                                        <InputError
                                            :message="errors.planned_end"
                                        />
                                    </div>

                                    <div class="flex items-end gap-2">
                                        <div class="flex items-center gap-2">
                                            <input
                                                :id="`paid-${shift.id}`"
                                                type="checkbox"
                                                name="paid"
                                                value="1"
                                            />
                                            <Label
                                                :for="`paid-${shift.id}`"
                                                class="text-xs"
                                            >
                                                Pagado
                                            </Label>
                                        </div>
                                        <Button
                                            type="submit"
                                            :disabled="processing"
                                            size="sm"
                                        >
                                            <Spinner v-if="processing" />
                                            Guardar
                                        </Button>
                                    </div>
                                </Form>
                            </td>
                        </tr>
                        <tr
                            v-if="
                                canManageSchedules &&
                                activeReassignFormShiftId === shift.id
                            "
                            class="border-b border-sidebar-border/40 bg-muted/30 last:border-0 dark:border-sidebar-border/40"
                        >
                            <td colspan="6" class="p-3">
                                <Form
                                    v-bind="
                                        ShiftAssignmentController.update.form(
                                            shift.id,
                                        )
                                    "
                                    reset-on-success
                                    @success="closeInlineForms"
                                    v-slot="{ errors, processing }"
                                    class="grid max-w-xl gap-4 sm:grid-cols-3"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`employee_id-${shift.id}`">
                                            Nuevo empleado
                                        </Label>
                                        <select
                                            :id="`employee_id-${shift.id}`"
                                            name="employee_id"
                                            required
                                            class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                                        >
                                            <option
                                                v-for="option in otherEmployees(
                                                    employee.id,
                                                )"
                                                :key="option.id"
                                                :value="option.id"
                                            >
                                                {{ option.full_name }}
                                            </option>
                                        </select>
                                        <InputError
                                            :message="errors.employee_id"
                                        />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label :for="`reason-${shift.id}`"
                                            >Motivo</Label
                                        >
                                        <Input
                                            :id="`reason-${shift.id}`"
                                            name="reason"
                                            required
                                            maxlength="500"
                                            placeholder="Ej: el empleado original se enfermó"
                                        />
                                        <InputError :message="errors.reason" />
                                    </div>

                                    <div class="flex items-end">
                                        <Button
                                            type="submit"
                                            :disabled="processing"
                                            size="sm"
                                        >
                                            <Spinner v-if="processing" />
                                            Reasignar
                                        </Button>
                                    </div>
                                </Form>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="shifts.length === 0">
                        <td
                            class="p-3 text-muted-foreground"
                            :colspan="canManageSchedules ? 6 : 5"
                        >
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

            <div
                class="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <Heading variant="small" title="Crear turno manual" />

                <Form
                    v-bind="ShiftController.store.form(employee.id)"
                    reset-on-success
                    v-slot="{ errors, processing }"
                    class="grid gap-4"
                >
                    <div class="grid gap-2">
                        <Label for="date">Fecha</Label>
                        <Input id="date" type="date" name="date" required />
                        <InputError :message="errors.date" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="start_datetime">Inicio</Label>
                        <Input
                            id="start_datetime"
                            type="datetime-local"
                            name="start_datetime"
                            required
                        />
                        <InputError :message="errors.start_datetime" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="end_datetime">Fin</Label>
                        <Input
                            id="end_datetime"
                            type="datetime-local"
                            name="end_datetime"
                            required
                        />
                        <InputError :message="errors.end_datetime" />
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            id="crosses_midnight"
                            type="checkbox"
                            name="crosses_midnight"
                            value="1"
                        />
                        <Label for="crosses_midnight" class="text-xs">
                            Cruza medianoche
                        </Label>
                    </div>

                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        Crear turno
                    </Button>
                </Form>
            </div>
        </div>
    </div>
</template>

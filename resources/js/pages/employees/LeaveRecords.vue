<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import LeaveRecordController from '@/actions/App/Http/Controllers/LeaveRecordController';
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

type LeaveTypeOption = {
    id: string;
    name: string;
};

type LeaveRecordRow = {
    id: string;
    leave_type: string;
    date_from: string;
    date_to: string;
    reason: string;
    status: string;
    approved_by: string | null;
};

defineProps<{
    employee: EmployeeDetail;
    records: LeaveRecordRow[];
    leaveTypes: LeaveTypeOption[];
    canCreate: boolean;
    canApprove: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Empleados', href: index() },
        ],
    },
});

const statusLabels: Record<string, string> = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado',
};
</script>

<template>
    <Head :title="`Licencias de ${employee.full_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Licencias de ${employee.full_name}`"
            description="Solicitudes de licencia y ausencia"
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
                        <th class="p-3 font-medium">Desde</th>
                        <th class="p-3 font-medium">Hasta</th>
                        <th class="p-3 font-medium">Motivo</th>
                        <th class="p-3 font-medium">Estado</th>
                        <th class="p-3 font-medium">Aprobado por</th>
                        <th v-if="canApprove" class="p-3 font-medium">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="record in records"
                        :key="record.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">{{ record.leave_type }}</td>
                        <td class="p-3">{{ record.date_from }}</td>
                        <td class="p-3">{{ record.date_to }}</td>
                        <td class="p-3">{{ record.reason }}</td>
                        <td class="p-3">
                            {{ statusLabels[record.status] ?? record.status }}
                        </td>
                        <td class="p-3">{{ record.approved_by ?? '—' }}</td>
                        <td v-if="canApprove" class="p-3">
                            <div
                                v-if="record.status === 'pending'"
                                class="flex gap-2"
                            >
                                <Form
                                    v-bind="
                                        LeaveRecordController.approve.form(
                                            record.id,
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
                                        LeaveRecordController.reject.form(
                                            record.id,
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
                    <tr v-if="records.length === 0">
                        <td
                            class="p-3 text-muted-foreground"
                            :colspan="canApprove ? 7 : 6"
                        >
                            Todavía no hay licencias solicitadas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canCreate"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Solicitar licencia" />

            <Form
                v-bind="LeaveRecordController.store.form(employee.id)"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="leave_type_id">Tipo de licencia</Label>
                    <select
                        id="leave_type_id"
                        name="leave_type_id"
                        required
                        class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                    >
                        <option
                            v-for="leaveType in leaveTypes"
                            :key="leaveType.id"
                            :value="leaveType.id"
                        >
                            {{ leaveType.name }}
                        </option>
                    </select>
                    <InputError :message="errors.leave_type_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="date_from">Fecha inicial</Label>
                    <Input
                        id="date_from"
                        type="date"
                        name="date_from"
                        required
                    />
                    <InputError :message="errors.date_from" />
                </div>

                <div class="grid gap-2">
                    <Label for="date_to">Fecha final</Label>
                    <Input id="date_to" type="date" name="date_to" required />
                    <InputError :message="errors.date_to" />
                </div>

                <div class="grid gap-2">
                    <Label for="reason">Motivo</Label>
                    <Input
                        id="reason"
                        name="reason"
                        required
                        maxlength="255"
                        placeholder="Ej: vacaciones programadas"
                    />
                    <InputError :message="errors.reason" />
                </div>

                <div class="grid gap-2">
                    <Label for="document_ref">
                        Documento de soporte (opcional)
                    </Label>
                    <Input
                        id="document_ref"
                        name="document_ref"
                        maxlength="255"
                        placeholder="Ej: incapacidad-123.pdf"
                    />
                    <InputError :message="errors.document_ref" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Solicitar licencia
                </Button>
            </Form>
        </div>
    </div>
</template>

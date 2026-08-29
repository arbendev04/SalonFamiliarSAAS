<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import OvertimeRecordController from '@/actions/App/Http/Controllers/OvertimeRecordController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/employees';

type EmployeeDetail = {
    id: string;
    full_name: string;
};

type OvertimeRecordRow = {
    id: string;
    shift_date: string;
    detected_minutes: number;
    requested_minutes: number | null;
    authorized_minutes: number | null;
    status: string;
};

defineProps<{
    employee: EmployeeDetail;
    records: OvertimeRecordRow[];
    canRequest: boolean;
    canAuthorize: boolean;
    canMarkPaid: boolean;
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
    detected: 'Detectada',
    requested: 'Solicitada',
    authorized: 'Autorizada',
    rejected: 'Rechazada',
    paid: 'Pagada',
};
</script>

<template>
    <Head :title="`Horas extra de ${employee.full_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Horas extra de ${employee.full_name}`"
            description="Horas extra detectadas y su ciclo de aprobación"
        />

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Turno</th>
                        <th class="p-3 font-medium">Detectadas</th>
                        <th class="p-3 font-medium">Solicitadas</th>
                        <th class="p-3 font-medium">Autorizadas</th>
                        <th class="p-3 font-medium">Estado</th>
                        <th
                            v-if="canRequest || canAuthorize || canMarkPaid"
                            class="p-3 font-medium"
                        >
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
                        <td class="p-3">{{ record.shift_date }}</td>
                        <td class="p-3">{{ record.detected_minutes }}</td>
                        <td class="p-3">
                            {{ record.requested_minutes ?? '—' }}
                        </td>
                        <td class="p-3">
                            {{ record.authorized_minutes ?? '—' }}
                        </td>
                        <td class="p-3">
                            {{ statusLabels[record.status] ?? record.status }}
                        </td>
                        <td
                            v-if="canRequest || canAuthorize || canMarkPaid"
                            class="p-3"
                        >
                            <Form
                                v-if="
                                    record.status === 'detected' && canRequest
                                "
                                v-bind="
                                    OvertimeRecordController.request.form(
                                        record.id,
                                    )
                                "
                                v-slot="{ errors, processing }"
                                class="flex items-start gap-2"
                            >
                                <div class="grid gap-1">
                                    <Input
                                        type="number"
                                        name="requested_minutes"
                                        min="1"
                                        required
                                        placeholder="Minutos"
                                        class="w-24"
                                    />
                                    <InputError
                                        :message="errors.requested_minutes"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="processing"
                                >
                                    <Spinner v-if="processing" />
                                    Solicitar
                                </Button>
                            </Form>

                            <div
                                v-else-if="
                                    record.status === 'requested' &&
                                    canAuthorize
                                "
                                class="flex items-start gap-2"
                            >
                                <Form
                                    v-bind="
                                        OvertimeRecordController.authorize.form(
                                            record.id,
                                        )
                                    "
                                    v-slot="{ errors, processing: authorizing }"
                                    class="flex items-start gap-2"
                                >
                                    <div class="grid gap-1">
                                        <Input
                                            type="number"
                                            name="authorized_minutes"
                                            min="1"
                                            required
                                            placeholder="Minutos"
                                            class="w-24"
                                        />
                                        <InputError
                                            :message="errors.authorized_minutes"
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="authorizing"
                                    >
                                        <Spinner v-if="authorizing" />
                                        Autorizar
                                    </Button>
                                </Form>
                                <Form
                                    v-bind="
                                        OvertimeRecordController.reject.form(
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

                            <Form
                                v-else-if="
                                    record.status === 'authorized' &&
                                    canMarkPaid
                                "
                                v-bind="
                                    OvertimeRecordController.markPaid.form(
                                        record.id,
                                    )
                                "
                                v-slot="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="processing"
                                >
                                    <Spinner v-if="processing" />
                                    Marcar como pagada
                                </Button>
                            </Form>

                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                    <tr v-if="records.length === 0">
                        <td
                            class="p-3 text-muted-foreground"
                            :colspan="
                                canRequest || canAuthorize || canMarkPaid
                                    ? 6
                                    : 5
                            "
                        >
                            Todavía no hay horas extra detectadas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

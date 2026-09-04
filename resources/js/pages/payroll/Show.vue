<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import GeneratedDocumentController from '@/actions/App/Http/Controllers/GeneratedDocumentController';
import PayrollAdjustmentController from '@/actions/App/Http/Controllers/PayrollAdjustmentController';
import PayrollPeriodController from '@/actions/App/Http/Controllers/PayrollPeriodController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/payroll/periods';

type PayrollPeriodDetail = {
    id: string;
    period_type: string;
    start_date: string;
    end_date: string;
    status: string;
    closed_by: string | null;
    closed_at: string | null;
};

type PayrollEntryLineRow = {
    id: string;
    concept: string;
    type: string;
    quantity: string | null;
    rate: string | null;
    amount: string;
};

type GeneratedDocumentRow = {
    id: string;
    version: number;
    generated_at: string;
};

type PayrollEntryRow = {
    id: string;
    employee: { id: string; full_name: string };
    contract_id: string | null;
    status: string;
    gross_total: string;
    deductions_total: string;
    net_total: string;
    lines: PayrollEntryLineRow[];
    generated_documents: GeneratedDocumentRow[];
};

type ConceptOption = {
    id: string;
    code: string;
    name: string;
};

defineProps<{
    period: PayrollPeriodDetail;
    entries: PayrollEntryRow[];
    canCalculate: boolean;
    canApprove: boolean;
    canClose: boolean;
    canReopen: boolean;
    canAdjust: boolean;
    concepts: ConceptOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Periodos de nómina', href: index() },
        ],
    },
});

const statusLabels: Record<string, string> = {
    open: 'Abierto',
    calculated: 'Calculado',
    approved: 'Aprobado',
    closed: 'Cerrado',
    reopened: 'Reabierto',
    blocked: 'Bloqueada',
};

const periodTypeLabels: Record<string, string> = {
    weekly: 'Semanal',
    biweekly: 'Quincenal',
    monthly: 'Mensual',
};
</script>

<template>
    <Head
        :title="`Periodo de nómina ${period.start_date} - ${period.end_date}`"
    />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="`Periodo ${periodTypeLabels[period.period_type] ?? period.period_type}`"
                :description="`${period.start_date} al ${period.end_date} — ${statusLabels[period.status] ?? period.status}`"
            />
        </div>

        <p v-if="period.closed_by" class="text-sm text-muted-foreground">
            Cerrado por {{ period.closed_by }} el {{ period.closed_at }}
        </p>

        <div class="flex flex-wrap gap-2">
            <Form
                v-if="period.status !== 'closed' && canCalculate"
                v-bind="PayrollPeriodController.calculate.form(period.id)"
                v-slot="{ processing }"
            >
                <Button type="submit" size="sm" :disabled="processing">
                    <Spinner v-if="processing" />
                    Calcular
                </Button>
            </Form>

            <Form
                v-if="period.status === 'calculated' && canApprove"
                v-bind="PayrollPeriodController.approve.form(period.id)"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    size="sm"
                    variant="secondary"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Aprobar
                </Button>
            </Form>

            <Form
                v-if="
                    (period.status === 'calculated' ||
                        period.status === 'approved' ||
                        period.status === 'reopened') &&
                    canClose
                "
                v-bind="PayrollPeriodController.close.form(period.id)"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    size="sm"
                    variant="secondary"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Cerrar
                </Button>
            </Form>

            <Form
                v-if="period.status === 'closed' && canReopen"
                v-bind="PayrollPeriodController.reopen.form(period.id)"
                v-slot="{ errors, processing }"
                class="flex items-start gap-2"
            >
                <div class="grid gap-1">
                    <Input
                        name="reason"
                        placeholder="Motivo de reapertura"
                        required
                        class="w-64"
                    />
                    <InputError :message="errors.reason" />
                </div>
                <Button
                    type="submit"
                    size="sm"
                    variant="destructive"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Reabrir
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
                        <th class="p-3 font-medium">Empleado</th>
                        <th class="p-3 font-medium">Estado</th>
                        <th class="p-3 font-medium">Devengado</th>
                        <th class="p-3 font-medium">Deducido</th>
                        <th class="p-3 font-medium">Neto</th>
                        <th class="p-3 font-medium">Comprobantes</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="entry in entries" :key="entry.id">
                        <tr
                            class="border-b border-sidebar-border/40 dark:border-sidebar-border/40"
                        >
                            <td class="p-3">{{ entry.employee.full_name }}</td>
                            <td class="p-3">
                                {{ statusLabels[entry.status] ?? entry.status }}
                            </td>
                            <td class="p-3">{{ entry.gross_total }}</td>
                            <td class="p-3">{{ entry.deductions_total }}</td>
                            <td class="p-3">{{ entry.net_total }}</td>
                            <td class="p-3">
                                <span
                                    v-if="
                                        entry.generated_documents.length === 0
                                    "
                                    class="text-muted-foreground"
                                >
                                    —
                                </span>
                                <a
                                    v-else-if="
                                        entry.generated_documents.length === 1
                                    "
                                    :href="
                                        GeneratedDocumentController.download.url(
                                            [
                                                entry.id,
                                                entry.generated_documents[0].id,
                                            ],
                                        )
                                    "
                                    class="text-primary underline"
                                >
                                    Descargar
                                </a>
                                <ul v-else class="space-y-1">
                                    <li
                                        v-for="document in entry.generated_documents"
                                        :key="document.id"
                                    >
                                        <a
                                            :href="
                                                GeneratedDocumentController.download.url(
                                                    [entry.id, document.id],
                                                )
                                            "
                                            class="text-primary underline"
                                        >
                                            v{{ document.version }}
                                        </a>
                                    </li>
                                </ul>
                            </td>
                        </tr>
                        <tr
                            v-for="line in entry.lines"
                            :key="line.id"
                            class="border-b border-sidebar-border/20 text-muted-foreground last:border-0 dark:border-sidebar-border/20"
                        >
                            <td class="p-3 pl-6" colspan="2">
                                {{ line.concept }} ({{ line.type }})
                            </td>
                            <td class="p-3" colspan="2">
                                {{ line.quantity ?? '—' }} x
                                {{ line.rate ?? '—' }}
                            </td>
                            <td class="p-3" colspan="2">{{ line.amount }}</td>
                        </tr>
                        <tr
                            v-if="entry.status === 'blocked'"
                            class="border-b border-sidebar-border/20 last:border-0 dark:border-sidebar-border/20"
                        >
                            <td class="p-3 pl-6 text-destructive" colspan="6">
                                Entrada bloqueada — revise los datos del
                                empleado y recalcule.
                            </td>
                        </tr>
                        <tr
                            v-if="canAdjust && period.status === 'closed'"
                            class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                        >
                            <td class="p-3 pl-6" colspan="6">
                                <Form
                                    v-bind="
                                        PayrollAdjustmentController.store.form(
                                            entry.id,
                                        )
                                    "
                                    reset-on-success
                                    v-slot="{ errors, processing }"
                                    class="flex flex-wrap items-start gap-2"
                                >
                                    <div class="grid gap-1">
                                        <select
                                            name="concept_id"
                                            required
                                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                        >
                                            <option
                                                v-for="concept in concepts"
                                                :key="concept.id"
                                                :value="concept.id"
                                            >
                                                {{ concept.name }}
                                            </option>
                                        </select>
                                        <InputError
                                            :message="errors.concept_id"
                                        />
                                    </div>
                                    <div class="grid gap-1">
                                        <Input
                                            type="number"
                                            name="amount"
                                            step="0.01"
                                            min="0.01"
                                            required
                                            placeholder="Monto"
                                            class="w-32"
                                        />
                                        <InputError :message="errors.amount" />
                                    </div>
                                    <div class="grid gap-1">
                                        <select
                                            name="type"
                                            required
                                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                        >
                                            <option value="earning">
                                                Devengo
                                            </option>
                                            <option value="deduction">
                                                Deducción
                                            </option>
                                        </select>
                                        <InputError :message="errors.type" />
                                    </div>
                                    <div class="grid gap-1">
                                        <Input
                                            name="reason"
                                            required
                                            placeholder="Motivo"
                                            class="w-56"
                                        />
                                        <InputError :message="errors.reason" />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="processing"
                                    >
                                        <Spinner v-if="processing" />
                                        Solicitar ajuste
                                    </Button>
                                </Form>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="entries.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="6">
                            Todavía no hay entradas calculadas para este
                            periodo.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import PayrollPeriodController from '@/actions/App/Http/Controllers/PayrollPeriodController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { show } from '@/routes/payroll/periods';

type PayrollPeriodRow = {
    id: string;
    period_type: string;
    start_date: string;
    end_date: string;
    status: string;
};

defineProps<{
    periods: PayrollPeriodRow[];
    canCreate: boolean;
    canCalculate: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel', href: dashboard() }],
    },
});

const statusLabels: Record<string, string> = {
    open: 'Abierto',
    calculated: 'Calculado',
    approved: 'Aprobado',
    closed: 'Cerrado',
    reopened: 'Reabierto',
};

const periodTypeLabels: Record<string, string> = {
    weekly: 'Semanal',
    biweekly: 'Quincenal',
    monthly: 'Mensual',
};
</script>

<template>
    <Head title="Periodos de nómina" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Periodos de nómina"
            description="Periodos de liquidación de nómina de la empresa"
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
                        <th class="p-3 font-medium">Inicio</th>
                        <th class="p-3 font-medium">Fin</th>
                        <th class="p-3 font-medium">Estado</th>
                        <th class="p-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="period in periods"
                        :key="period.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">
                            {{
                                periodTypeLabels[period.period_type] ??
                                period.period_type
                            }}
                        </td>
                        <td class="p-3">{{ period.start_date }}</td>
                        <td class="p-3">{{ period.end_date }}</td>
                        <td class="p-3">
                            {{ statusLabels[period.status] ?? period.status }}
                        </td>
                        <td class="p-3">
                            <Link
                                :href="show(period.id)"
                                class="text-sm underline underline-offset-4"
                            >
                                Ver detalle
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="periods.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="5">
                            Todavía no hay periodos de nómina.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canCreate"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Crear periodo" />

            <Form
                v-bind="PayrollPeriodController.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="period_type">Tipo de periodo</Label>
                    <select
                        id="period_type"
                        name="period_type"
                        required
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="weekly">Semanal</option>
                        <option value="biweekly">Quincenal</option>
                        <option value="monthly">Mensual</option>
                    </select>
                    <InputError :message="errors.period_type" />
                </div>

                <div class="grid gap-2">
                    <Label for="start_date">Fecha de inicio</Label>
                    <Input
                        id="start_date"
                        type="date"
                        name="start_date"
                        required
                    />
                    <InputError :message="errors.start_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="end_date">Fecha de fin</Label>
                    <Input id="end_date" type="date" name="end_date" required />
                    <InputError :message="errors.end_date" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Crear periodo
                </Button>
            </Form>
        </div>
    </div>
</template>

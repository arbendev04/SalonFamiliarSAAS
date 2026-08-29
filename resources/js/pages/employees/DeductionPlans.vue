<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import PayrollDeductionPlanController from '@/actions/App/Http/Controllers/PayrollDeductionPlanController';
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

type DeductionPlanRow = {
    id: string;
    concept: string;
    total_amount: string;
    installments: number;
    installment_amount: string;
    remaining: string;
};

type ConceptOption = {
    id: string;
    code: string;
    name: string;
};

defineProps<{
    employee: EmployeeDetail;
    plans: DeductionPlanRow[];
    concepts: ConceptOption[];
    canManage: boolean;
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
    <Head :title="`Planes de deducción de ${employee.full_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Planes de deducción de ${employee.full_name}`"
            description="Préstamos y embargos aplicados a la nómina de este empleado"
        />

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Concepto</th>
                        <th class="p-3 font-medium">Total</th>
                        <th class="p-3 font-medium">Cuotas</th>
                        <th class="p-3 font-medium">Valor cuota</th>
                        <th class="p-3 font-medium">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="plan in plans"
                        :key="plan.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">{{ plan.concept }}</td>
                        <td class="p-3">{{ plan.total_amount }}</td>
                        <td class="p-3">{{ plan.installments }}</td>
                        <td class="p-3">{{ plan.installment_amount }}</td>
                        <td class="p-3">{{ plan.remaining }}</td>
                    </tr>
                    <tr v-if="plans.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="5">
                            Todavía no hay planes de deducción.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManage"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Crear plan de deducción" />

            <Form
                v-bind="PayrollDeductionPlanController.store.form(employee.id)"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="concept_id">Concepto</Label>
                    <select
                        id="concept_id"
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
                    <InputError :message="errors.concept_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="total_amount">Monto total</Label>
                    <Input
                        id="total_amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        name="total_amount"
                        required
                    />
                    <InputError :message="errors.total_amount" />
                </div>

                <div class="grid gap-2">
                    <Label for="installments">Número de cuotas</Label>
                    <Input
                        id="installments"
                        type="number"
                        min="1"
                        name="installments"
                        required
                    />
                    <InputError :message="errors.installments" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Crear plan
                </Button>
            </Form>
        </div>
    </div>
</template>

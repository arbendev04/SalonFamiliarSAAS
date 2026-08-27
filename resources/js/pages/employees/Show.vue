<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import EmploymentContractController from '@/actions/App/Http/Controllers/EmploymentContractController';
import PayrollInformationController from '@/actions/App/Http/Controllers/PayrollInformationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/employees';
import { index as shiftsIndex } from '@/routes/employees/shifts';

type EmployeeDetail = {
    id: string;
    full_name: string;
    national_id: string;
    status: string;
    hire_date: string;
};

type ContractRow = {
    id: string;
    contract_type: string;
    start_date: string;
    end_date: string | null;
    base_salary: string;
    status: string;
    position: string | null;
};

type PayrollInformationDetail = {
    bank_account_enc: string;
    tax_regime: string | null;
};

defineProps<{
    employee: EmployeeDetail;
    contracts: ContractRow[];
    payrollInformation: PayrollInformationDetail | null;
    canManageContracts: boolean;
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
    <Head :title="employee.full_name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                :title="employee.full_name"
                :description="`Cédula ${employee.national_id}`"
            />
            <Link
                :href="shiftsIndex(employee.id)"
                class="text-sm underline underline-offset-4"
            >
                Ver turnos
            </Link>
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Cargo</th>
                        <th class="p-3 font-medium">Tipo</th>
                        <th class="p-3 font-medium">Inicio</th>
                        <th class="p-3 font-medium">Fin</th>
                        <th class="p-3 font-medium">Salario base</th>
                        <th class="p-3 font-medium">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="contract in contracts"
                        :key="contract.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">{{ contract.position ?? '—' }}</td>
                        <td class="p-3">{{ contract.contract_type }}</td>
                        <td class="p-3">{{ contract.start_date }}</td>
                        <td class="p-3">
                            {{ contract.end_date ?? 'Vigente' }}
                        </td>
                        <td class="p-3">{{ contract.base_salary }}</td>
                        <td class="p-3">{{ contract.status }}</td>
                    </tr>
                    <tr v-if="contracts.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="6">
                            Todavía no hay contratos.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManageContracts"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Agregar contrato" />

            <Form
                v-bind="EmploymentContractController.store.form(employee.id)"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="contract_type">Tipo de contrato</Label>
                    <Input
                        id="contract_type"
                        name="contract_type"
                        required
                        placeholder="Término indefinido"
                    />
                    <InputError :message="errors.contract_type" />
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
                    <Label for="end_date">Fecha de fin (opcional)</Label>
                    <Input id="end_date" type="date" name="end_date" />
                    <InputError :message="errors.end_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="base_salary">Salario base</Label>
                    <Input
                        id="base_salary"
                        type="number"
                        step="0.01"
                        min="0"
                        name="base_salary"
                        required
                    />
                    <InputError :message="errors.base_salary" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Agregar contrato
                </Button>
            </Form>
        </div>

        <div
            v-if="canManageContracts"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Datos de pago" />

            <Form
                v-bind="PayrollInformationController.store.form(employee.id)"
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="bank_account_enc">Cuenta bancaria</Label>
                    <Input
                        id="bank_account_enc"
                        name="bank_account_enc"
                        required
                        :default-value="payrollInformation?.bank_account_enc"
                    />
                    <InputError :message="errors.bank_account_enc" />
                </div>

                <div class="grid gap-2">
                    <Label for="tax_regime">Régimen tributario</Label>
                    <Input
                        id="tax_regime"
                        name="tax_regime"
                        :default-value="
                            payrollInformation?.tax_regime ?? undefined
                        "
                    />
                    <InputError :message="errors.tax_regime" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Guardar
                </Button>
            </Form>
        </div>
    </div>
</template>

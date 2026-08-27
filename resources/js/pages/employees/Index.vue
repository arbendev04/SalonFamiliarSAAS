<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import EmployeeController from '@/actions/App/Http/Controllers/EmployeeController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/employees';

type EmployeeRow = {
    id: string;
    full_name: string;
    national_id: string;
    status: string;
    hire_date: string;
};

defineProps<{
    employees: EmployeeRow[];
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
    <Head title="Empleados" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Empleados"
            description="Trabajadores registrados en tu empresa"
        />

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Nombre</th>
                        <th class="p-3 font-medium">Documento</th>
                        <th class="p-3 font-medium">Estado</th>
                        <th class="p-3 font-medium">Fecha de ingreso</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="employee in employees"
                        :key="employee.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">{{ employee.full_name }}</td>
                        <td class="p-3">{{ employee.national_id }}</td>
                        <td class="p-3">{{ employee.status }}</td>
                        <td class="p-3">{{ employee.hire_date }}</td>
                    </tr>
                    <tr v-if="employees.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="4">
                            Todavía no hay empleados.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Agregar empleado" />

            <Form
                v-bind="EmployeeController.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="full_name">Nombre completo</Label>
                    <Input id="full_name" name="full_name" required />
                    <InputError :message="errors.full_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="national_id">Cédula</Label>
                    <Input id="national_id" name="national_id" required />
                    <InputError :message="errors.national_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="hire_date">Fecha de ingreso</Label>
                    <Input
                        id="hire_date"
                        type="date"
                        name="hire_date"
                        required
                    />
                    <InputError :message="errors.hire_date" />
                </div>

                <input type="hidden" name="document_type" value="CC" />

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Agregar empleado
                </Button>
            </Form>
        </div>
    </div>
</template>

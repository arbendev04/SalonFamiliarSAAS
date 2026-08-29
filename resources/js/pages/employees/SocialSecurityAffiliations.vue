<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SocialSecurityAffiliationController from '@/actions/App/Http/Controllers/SocialSecurityAffiliationController';
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

type AffiliationRow = {
    id: string;
    entity: string;
    entity_type: string;
    affiliation_number: string | null;
    start_date: string;
    end_date: string | null;
    is_active: boolean;
};

type EntityOption = {
    id: string;
    type: string;
    name: string;
    code: string;
};

defineProps<{
    employee: EmployeeDetail;
    affiliations: AffiliationRow[];
    entities: EntityOption[];
    entityTypesWithoutActiveAffiliation: string[];
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
    <Head
        :title="`Afiliaciones de seguridad social de ${employee.full_name}`"
    />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Afiliaciones de seguridad social de ${employee.full_name}`"
            description="Historial de afiliaciones a entidades de seguridad social de este empleado"
        />

        <div
            v-if="entityTypesWithoutActiveAffiliation.length > 0"
            class="rounded-xl border border-sidebar-border/70 p-4 text-sm text-muted-foreground dark:border-sidebar-border"
        >
            Sin afiliación activa para:
            {{ entityTypesWithoutActiveAffiliation.join(', ') }}
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Entidad</th>
                        <th class="p-3 font-medium">Tipo</th>
                        <th class="p-3 font-medium">Número de afiliación</th>
                        <th class="p-3 font-medium">Inicio</th>
                        <th class="p-3 font-medium">Fin</th>
                        <th class="p-3 font-medium">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="affiliation in affiliations"
                        :key="affiliation.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">{{ affiliation.entity }}</td>
                        <td class="p-3">{{ affiliation.entity_type }}</td>
                        <td class="p-3">
                            {{ affiliation.affiliation_number ?? '—' }}
                        </td>
                        <td class="p-3">{{ affiliation.start_date }}</td>
                        <td class="p-3">
                            {{ affiliation.end_date ?? 'Vigente' }}
                        </td>
                        <td class="p-3">
                            <span
                                :class="
                                    affiliation.is_active
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    affiliation.is_active ? 'Activa' : 'Cerrada'
                                }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="affiliations.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="6">
                            Todavía no hay afiliaciones registradas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManage"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading
                variant="small"
                title="Afiliar o reasignar entidad"
                description="Si el empleado ya tiene una afiliación activa del mismo tipo, se cierra automáticamente y se abre la nueva."
            />

            <Form
                v-bind="
                    SocialSecurityAffiliationController.store.form(employee.id)
                "
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <input type="hidden" name="employee_id" :value="employee.id" />

                <div class="grid gap-2">
                    <Label for="entity_id">Entidad</Label>
                    <select
                        id="entity_id"
                        name="entity_id"
                        required
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option
                            v-for="entity in entities"
                            :key="entity.id"
                            :value="entity.id"
                        >
                            {{ entity.name }} ({{ entity.type }})
                        </option>
                    </select>
                    <InputError :message="errors.entity_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="affiliation_number"
                        >Número de afiliación (opcional)</Label
                    >
                    <Input id="affiliation_number" name="affiliation_number" />
                    <InputError :message="errors.affiliation_number" />
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

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Guardar afiliación
                </Button>
            </Form>
        </div>
    </div>
</template>

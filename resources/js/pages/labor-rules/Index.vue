<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import LaborRuleVersionController from '@/actions/App/Http/Controllers/LaborRuleVersionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/labor-rules';

type LaborRuleVersionRow = {
    id: string;
    effective_from: string;
    effective_to: string | null;
    parameters: {
        tolerance_minutes: number;
        rounding_minutes: number;
    };
    created_by: string | null;
};

defineProps<{
    laborRuleId: string;
    versions: LaborRuleVersionRow[];
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Reglas laborales', href: index() },
        ],
    },
});
</script>

<template>
    <Head title="Reglas laborales" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Reglas laborales"
            description="Parámetros de tolerancia y redondeo vigentes por vigencia"
        />

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Vigencia</th>
                        <th class="p-3 font-medium">Tolerancia (min)</th>
                        <th class="p-3 font-medium">Redondeo (min)</th>
                        <th class="p-3 font-medium">Creado por</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="version in versions"
                        :key="version.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">
                            {{ version.effective_from }} –
                            {{ version.effective_to ?? 'Indefinido' }}
                        </td>
                        <td class="p-3">
                            {{ version.parameters.tolerance_minutes }}
                        </td>
                        <td class="p-3">
                            {{ version.parameters.rounding_minutes }}
                        </td>
                        <td class="p-3">
                            {{ version.created_by ?? '—' }}
                        </td>
                    </tr>
                    <tr v-if="versions.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="4">
                            Todavía no hay versiones de reglas laborales.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManage"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Crear versión" />

            <Form
                v-bind="LaborRuleVersionController.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <input
                    type="hidden"
                    name="labor_rule_id"
                    :value="laborRuleId"
                />

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

                <div class="grid gap-2">
                    <Label for="effective_to">Vigente hasta (opcional)</Label>
                    <Input id="effective_to" type="date" name="effective_to" />
                    <InputError :message="errors.effective_to" />
                </div>

                <div class="grid gap-2">
                    <Label for="tolerance_minutes">Tolerancia (minutos)</Label>
                    <Input
                        id="tolerance_minutes"
                        type="number"
                        min="0"
                        name="parameters[tolerance_minutes]"
                        required
                    />
                    <InputError
                        :message="errors['parameters.tolerance_minutes']"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="rounding_minutes">Redondeo (minutos)</Label>
                    <Input
                        id="rounding_minutes"
                        type="number"
                        min="1"
                        name="parameters[rounding_minutes]"
                        required
                    />
                    <InputError
                        :message="errors['parameters.rounding_minutes']"
                    />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Crear versión
                </Button>
            </Form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import SocialSecurityRuleVersionController from '@/actions/App/Http/Controllers/SocialSecurityRuleVersionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index as conceptDefinitionsIndex } from '@/routes/social-security/concept-definitions';

type RuleVersionRow = {
    id: string;
    effective_from: string;
    effective_to: string | null;
    parameters: {
        employee_pct: number;
        employer_pct: number;
        base_concept_codes: string[];
    };
    created_by: string | null;
};

type ConceptDetail = {
    id: string;
    name: string;
};

type PayrollConceptOption = {
    code: string;
    name: string;
};

const props = defineProps<{
    concept: ConceptDetail;
    laborRuleId: string;
    versions: RuleVersionRow[];
    payrollConcepts: PayrollConceptOption[];
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            {
                title: 'Conceptos de seguridad social',
                href: conceptDefinitionsIndex(),
            },
        ],
    },
});

function formatPct(pct: number): string {
    return `${(pct * 100).toFixed(2)}%`;
}
</script>

<template>
    <Head :title="`Tasas de aporte — ${props.concept.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-col gap-2">
            <Link
                :href="conceptDefinitionsIndex()"
                class="text-sm underline underline-offset-4"
            >
                Volver a conceptos de seguridad social
            </Link>
            <Heading
                :title="`Tasas de aporte — ${props.concept.name}`"
                description="Versiones vigentes por rango de fechas para este concepto"
            />
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Vigencia</th>
                        <th class="p-3 font-medium">% Empleado</th>
                        <th class="p-3 font-medium">% Empleador</th>
                        <th class="p-3 font-medium">Códigos base</th>
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
                            {{ formatPct(version.parameters.employee_pct) }}
                        </td>
                        <td class="p-3">
                            {{ formatPct(version.parameters.employer_pct) }}
                        </td>
                        <td class="p-3">
                            {{
                                version.parameters.base_concept_codes.join(', ')
                            }}
                        </td>
                        <td class="p-3">
                            {{ version.created_by ?? '—' }}
                        </td>
                    </tr>
                    <tr v-if="versions.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="5">
                            Todavía no hay versiones de tasa para este concepto.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManage"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Crear versión de tasa" />

            <Form
                v-bind="
                    SocialSecurityRuleVersionController.store.form(
                        props.concept.id,
                    )
                "
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
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
                    <Label for="employee_pct">
                        Porcentaje a cargo del empleado (0 a 1)
                    </Label>
                    <Input
                        id="employee_pct"
                        type="number"
                        step="0.0001"
                        min="0"
                        max="1"
                        name="parameters[employee_pct]"
                        required
                    />
                    <InputError :message="errors['parameters.employee_pct']" />
                </div>

                <div class="grid gap-2">
                    <Label for="employer_pct">
                        Porcentaje a cargo del empleador (0 a 1)
                    </Label>
                    <Input
                        id="employer_pct"
                        type="number"
                        step="0.0001"
                        min="0"
                        max="1"
                        name="parameters[employer_pct]"
                        required
                    />
                    <InputError :message="errors['parameters.employer_pct']" />
                </div>

                <div class="grid gap-2">
                    <Label for="base_concept_codes">
                        Conceptos base (mantén Ctrl/Cmd para elegir varios)
                    </Label>
                    <select
                        id="base_concept_codes"
                        name="parameters[base_concept_codes][]"
                        multiple
                        required
                        class="h-24 rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    >
                        <option
                            v-for="option in payrollConcepts"
                            :key="option.code"
                            :value="option.code"
                        >
                            {{ option.name }} ({{ option.code }})
                        </option>
                    </select>
                    <InputError
                        :message="errors['parameters.base_concept_codes']"
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

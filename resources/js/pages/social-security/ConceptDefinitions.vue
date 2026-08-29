<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import SocialSecurityConceptDefinitionController from '@/actions/App/Http/Controllers/SocialSecurityConceptDefinitionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/social-security/concept-definitions';

type SocialSecurityConceptDefinitionRow = {
    id: string;
    code: string;
    name: string;
    entity_type: string;
    is_platform_default: boolean;
};

defineProps<{
    concepts: SocialSecurityConceptDefinitionRow[];
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Conceptos de seguridad social', href: index() },
        ],
    },
});

const editingId = ref<string | null>(null);

function startEditing(conceptId: string) {
    editingId.value = conceptId;
}

function cancelEditing() {
    editingId.value = null;
}

function deleteConcept(concept: SocialSecurityConceptDefinitionRow) {
    if (confirm(`¿Eliminar el concepto "${concept.name}"?`)) {
        router.delete(
            SocialSecurityConceptDefinitionController.destroy.url(concept.id),
        );
    }
}
</script>

<template>
    <Head title="Conceptos de seguridad social" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Conceptos de seguridad social"
            description="Conceptos de aporte que tu empresa configura para sus entidades"
        />

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Código</th>
                        <th class="p-3 font-medium">Nombre</th>
                        <th class="p-3 font-medium">Tipo de entidad</th>
                        <th class="p-3 font-medium">Origen</th>
                        <th class="p-3 font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="concept in concepts"
                        :key="concept.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <template v-if="editingId === concept.id && canManage">
                            <td class="p-3" colspan="5">
                                <Form
                                    :action="
                                        SocialSecurityConceptDefinitionController.update.url(
                                            concept.id,
                                        )
                                    "
                                    method="put"
                                    v-slot="{ errors, processing }"
                                    class="flex flex-wrap items-end gap-4"
                                    @success="cancelEditing"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`code-${concept.id}`">
                                            Código
                                        </Label>
                                        <Input
                                            :id="`code-${concept.id}`"
                                            name="code"
                                            :default-value="concept.code"
                                            required
                                        />
                                        <InputError :message="errors.code" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label :for="`name-${concept.id}`">
                                            Nombre
                                        </Label>
                                        <Input
                                            :id="`name-${concept.id}`"
                                            name="name"
                                            :default-value="concept.name"
                                            required
                                        />
                                        <InputError :message="errors.name" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label
                                            :for="`entity_type-${concept.id}`"
                                        >
                                            Tipo de entidad
                                        </Label>
                                        <Input
                                            :id="`entity_type-${concept.id}`"
                                            name="entity_type"
                                            :default-value="concept.entity_type"
                                            required
                                        />
                                        <InputError
                                            :message="errors.entity_type"
                                        />
                                    </div>

                                    <div class="flex gap-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            :disabled="processing"
                                        >
                                            <Spinner v-if="processing" />
                                            Guardar
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            @click="cancelEditing"
                                        >
                                            Cancelar
                                        </Button>
                                    </div>
                                </Form>
                            </td>
                        </template>
                        <template v-else>
                            <td class="p-3">{{ concept.code }}</td>
                            <td class="p-3">{{ concept.name }}</td>
                            <td class="p-3">{{ concept.entity_type }}</td>
                            <td class="p-3">
                                <span
                                    v-if="concept.is_platform_default"
                                    class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                                >
                                    Predeterminado
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                                >
                                    Propio
                                </span>
                            </td>
                            <td class="p-3">
                                <div
                                    v-if="
                                        canManage &&
                                        !concept.is_platform_default
                                    "
                                    class="flex gap-2"
                                >
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="startEditing(concept.id)"
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        @click="deleteConcept(concept)"
                                    >
                                        Eliminar
                                    </Button>
                                </div>
                            </td>
                        </template>
                    </tr>
                    <tr v-if="concepts.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="5">
                            Todavía no hay conceptos.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManage"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Agregar concepto" />

            <Form
                v-bind="SocialSecurityConceptDefinitionController.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="code">Código</Label>
                    <Input id="code" name="code" required />
                    <InputError :message="errors.code" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Nombre</Label>
                    <Input id="name" name="name" required />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="entity_type">Tipo de entidad</Label>
                    <Input id="entity_type" name="entity_type" required />
                    <InputError :message="errors.entity_type" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Agregar concepto
                </Button>
            </Form>
        </div>
    </div>
</template>

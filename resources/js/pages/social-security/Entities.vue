<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import SocialSecurityEntityController from '@/actions/App/Http/Controllers/SocialSecurityEntityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/social-security/entities';

type SocialSecurityEntityRow = {
    id: string;
    type: string;
    name: string;
    code: string;
    is_platform_default: boolean;
};

defineProps<{
    entities: SocialSecurityEntityRow[];
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Entidades de seguridad social', href: index() },
        ],
    },
});

const editingId = ref<string | null>(null);

function startEditing(entityId: string) {
    editingId.value = entityId;
}

function cancelEditing() {
    editingId.value = null;
}

function deleteEntity(entity: SocialSecurityEntityRow) {
    if (confirm(`¿Eliminar la entidad "${entity.name}"?`)) {
        router.delete(SocialSecurityEntityController.destroy.url(entity.id));
    }
}
</script>

<template>
    <Head title="Entidades de seguridad social" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Entidades de seguridad social"
            description="Entidades con las que tu empresa afilia a sus empleados"
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
                        <th class="p-3 font-medium">Nombre</th>
                        <th class="p-3 font-medium">Código</th>
                        <th class="p-3 font-medium">Origen</th>
                        <th class="p-3 font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entity in entities"
                        :key="entity.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <template v-if="editingId === entity.id && canManage">
                            <td class="p-3" colspan="5">
                                <Form
                                    :action="
                                        SocialSecurityEntityController.update.url(
                                            entity.id,
                                        )
                                    "
                                    method="put"
                                    v-slot="{ errors, processing }"
                                    class="flex flex-wrap items-end gap-4"
                                    @success="cancelEditing"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`type-${entity.id}`">
                                            Tipo
                                        </Label>
                                        <Input
                                            :id="`type-${entity.id}`"
                                            name="type"
                                            :default-value="entity.type"
                                            required
                                        />
                                        <InputError :message="errors.type" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label :for="`name-${entity.id}`">
                                            Nombre
                                        </Label>
                                        <Input
                                            :id="`name-${entity.id}`"
                                            name="name"
                                            :default-value="entity.name"
                                            required
                                        />
                                        <InputError :message="errors.name" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label :for="`code-${entity.id}`">
                                            Código
                                        </Label>
                                        <Input
                                            :id="`code-${entity.id}`"
                                            name="code"
                                            :default-value="entity.code"
                                            required
                                        />
                                        <InputError :message="errors.code" />
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
                            <td class="p-3">{{ entity.type }}</td>
                            <td class="p-3">{{ entity.name }}</td>
                            <td class="p-3">{{ entity.code }}</td>
                            <td class="p-3">
                                <span
                                    v-if="entity.is_platform_default"
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
                                        canManage && !entity.is_platform_default
                                    "
                                    class="flex gap-2"
                                >
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="startEditing(entity.id)"
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        @click="deleteEntity(entity)"
                                    >
                                        Eliminar
                                    </Button>
                                </div>
                            </td>
                        </template>
                    </tr>
                    <tr v-if="entities.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="5">
                            Todavía no hay entidades.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManage"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Agregar entidad" />

            <Form
                v-bind="SocialSecurityEntityController.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="type">Tipo</Label>
                    <Input id="type" name="type" required />
                    <InputError :message="errors.type" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Nombre</Label>
                    <Input id="name" name="name" required />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="code">Código</Label>
                    <Input id="code" name="code" required />
                    <InputError :message="errors.code" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Agregar entidad
                </Button>
            </Form>
        </div>
    </div>
</template>

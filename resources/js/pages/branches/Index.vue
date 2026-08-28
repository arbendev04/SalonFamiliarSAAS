<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import BranchController from '@/actions/App/Http/Controllers/BranchController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/branches';

type BranchRow = {
    id: string;
    name: string;
    timezone: string;
};

defineProps<{
    branches: BranchRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Sedes', href: index() },
        ],
    },
});

const editingId = ref<string | null>(null);

function startEditing(branchId: string) {
    editingId.value = branchId;
}

function cancelEditing() {
    editingId.value = null;
}

function deleteBranch(branch: BranchRow) {
    if (confirm(`¿Eliminar la sede "${branch.name}"?`)) {
        router.delete(BranchController.destroy.url(branch.id));
    }
}
</script>

<template>
    <Head title="Sedes" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Sedes"
            description="Ubicaciones físicas de tu empresa"
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
                        <th class="p-3 font-medium">Zona horaria</th>
                        <th class="p-3 font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="branch in branches"
                        :key="branch.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <template v-if="editingId === branch.id">
                            <td class="p-3" colspan="3">
                                <Form
                                    :action="
                                        BranchController.update.url(branch.id)
                                    "
                                    method="put"
                                    v-slot="{ errors, processing }"
                                    class="flex flex-wrap items-end gap-4"
                                    @success="cancelEditing"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`name-${branch.id}`">
                                            Nombre
                                        </Label>
                                        <Input
                                            :id="`name-${branch.id}`"
                                            name="name"
                                            :default-value="branch.name"
                                            required
                                        />
                                        <InputError :message="errors.name" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label :for="`timezone-${branch.id}`">
                                            Zona horaria
                                        </Label>
                                        <Input
                                            :id="`timezone-${branch.id}`"
                                            name="timezone"
                                            :default-value="branch.timezone"
                                            required
                                        />
                                        <InputError
                                            :message="errors.timezone"
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
                            <td class="p-3">{{ branch.name }}</td>
                            <td class="p-3">{{ branch.timezone }}</td>
                            <td class="p-3">
                                <div class="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="startEditing(branch.id)"
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        @click="deleteBranch(branch)"
                                    >
                                        Eliminar
                                    </Button>
                                </div>
                            </td>
                        </template>
                    </tr>
                    <tr v-if="branches.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="3">
                            Todavía no hay sedes.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Agregar sede" />

            <Form
                v-bind="BranchController.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="name">Nombre</Label>
                    <Input id="name" name="name" required />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Zona horaria</Label>
                    <Input
                        id="timezone"
                        name="timezone"
                        required
                        default-value="America/Bogota"
                    />
                    <InputError :message="errors.timezone" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Agregar sede
                </Button>
            </Form>
        </div>
    </div>
</template>

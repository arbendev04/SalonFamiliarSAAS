<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PositionController from '@/actions/App/Http/Controllers/PositionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/positions';

type PositionRow = {
    id: string;
    code: string;
    title: string;
    department: string | null;
};

defineProps<{
    positions: PositionRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Cargos', href: index() },
        ],
    },
});

const editingId = ref<string | null>(null);

function startEditing(positionId: string) {
    editingId.value = positionId;
}

function cancelEditing() {
    editingId.value = null;
}

function deletePosition(position: PositionRow) {
    if (confirm(`¿Eliminar el cargo "${position.title}"?`)) {
        router.delete(PositionController.destroy.url(position.id));
    }
}
</script>

<template>
    <Head title="Cargos" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Cargos"
            description="Catálogo de puestos de trabajo de tu empresa"
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
                        <th class="p-3 font-medium">Título</th>
                        <th class="p-3 font-medium">Departamento</th>
                        <th class="p-3 font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="position in positions"
                        :key="position.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <template v-if="editingId === position.id">
                            <td class="p-3" colspan="4">
                                <Form
                                    :action="
                                        PositionController.update.url(
                                            position.id,
                                        )
                                    "
                                    method="put"
                                    v-slot="{ errors, processing }"
                                    class="flex flex-wrap items-end gap-4"
                                    @success="cancelEditing"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`code-${position.id}`">
                                            Código
                                        </Label>
                                        <Input
                                            :id="`code-${position.id}`"
                                            name="code"
                                            :default-value="position.code"
                                            required
                                        />
                                        <InputError :message="errors.code" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label :for="`title-${position.id}`">
                                            Título
                                        </Label>
                                        <Input
                                            :id="`title-${position.id}`"
                                            name="title"
                                            :default-value="position.title"
                                            required
                                        />
                                        <InputError :message="errors.title" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label
                                            :for="`department-${position.id}`"
                                        >
                                            Departamento
                                        </Label>
                                        <Input
                                            :id="`department-${position.id}`"
                                            name="department"
                                            :default-value="
                                                position.department ?? undefined
                                            "
                                        />
                                        <InputError
                                            :message="errors.department"
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
                            <td class="p-3">{{ position.code }}</td>
                            <td class="p-3">{{ position.title }}</td>
                            <td class="p-3">
                                {{ position.department ?? '—' }}
                            </td>
                            <td class="p-3">
                                <div class="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="startEditing(position.id)"
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        @click="deletePosition(position)"
                                    >
                                        Eliminar
                                    </Button>
                                </div>
                            </td>
                        </template>
                    </tr>
                    <tr v-if="positions.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="4">
                            Todavía no hay cargos.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Agregar cargo" />

            <Form
                v-bind="PositionController.store.form()"
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
                    <Label for="title">Título</Label>
                    <Input id="title" name="title" required />
                    <InputError :message="errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="department">Departamento</Label>
                    <Input id="department" name="department" />
                    <InputError :message="errors.department" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Agregar cargo
                </Button>
            </Form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import HolidayController from '@/actions/App/Http/Controllers/HolidayController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { index } from '@/routes/holidays';

type HolidayRow = {
    id: string;
    date: string;
    name: string;
    is_platform_default: boolean;
};

defineProps<{
    holidays: HolidayRow[];
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Festivos', href: index() },
        ],
    },
});

const editingId = ref<string | null>(null);

function startEditing(holidayId: string) {
    editingId.value = holidayId;
}

function cancelEditing() {
    editingId.value = null;
}

function deleteHoliday(holiday: HolidayRow) {
    if (confirm(`¿Eliminar el festivo "${holiday.name}"?`)) {
        router.delete(HolidayController.destroy.url(holiday.id));
    }
}
</script>

<template>
    <Head title="Festivos" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Festivos"
            description="Fechas festivas predeterminadas de la plataforma y las propias de tu empresa"
        />

        <div
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <tr>
                        <th class="p-3 font-medium">Fecha</th>
                        <th class="p-3 font-medium">Nombre</th>
                        <th class="p-3 font-medium">Origen</th>
                        <th class="p-3 font-medium">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="holiday in holidays"
                        :key="holiday.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <template v-if="editingId === holiday.id && canManage">
                            <td class="p-3" colspan="4">
                                <Form
                                    :action="
                                        HolidayController.update.url(holiday.id)
                                    "
                                    method="put"
                                    v-slot="{ errors, processing }"
                                    class="flex flex-wrap items-end gap-4"
                                    @success="cancelEditing"
                                >
                                    <div class="grid gap-2">
                                        <Label :for="`date-${holiday.id}`">
                                            Fecha
                                        </Label>
                                        <Input
                                            :id="`date-${holiday.id}`"
                                            type="date"
                                            name="date"
                                            :default-value="holiday.date"
                                            required
                                        />
                                        <InputError :message="errors.date" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label :for="`name-${holiday.id}`">
                                            Nombre
                                        </Label>
                                        <Input
                                            :id="`name-${holiday.id}`"
                                            name="name"
                                            :default-value="holiday.name"
                                            required
                                        />
                                        <InputError :message="errors.name" />
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
                            <td class="p-3">{{ holiday.date }}</td>
                            <td class="p-3">{{ holiday.name }}</td>
                            <td class="p-3">
                                <span
                                    v-if="holiday.is_platform_default"
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
                                        !holiday.is_platform_default
                                    "
                                    class="flex gap-2"
                                >
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="startEditing(holiday.id)"
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        @click="deleteHoliday(holiday)"
                                    >
                                        Eliminar
                                    </Button>
                                </div>
                            </td>
                        </template>
                    </tr>
                    <tr v-if="holidays.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="4">
                            Todavía no hay festivos.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="canManage"
            class="max-w-md space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Agregar festivo" />

            <Form
                v-bind="HolidayController.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="date">Fecha</Label>
                    <Input id="date" type="date" name="date" required />
                    <InputError :message="errors.date" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Nombre</Label>
                    <Input id="name" name="name" required />
                    <InputError :message="errors.name" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Agregar festivo
                </Button>
            </Form>
        </div>
    </div>
</template>

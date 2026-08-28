<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import WorkScheduleTemplateController from '@/actions/App/Http/Controllers/WorkScheduleTemplateController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';

type DayRow = {
    id: string;
    day_of_week: number;
    start_time: string;
    end_time: string;
    crosses_midnight: boolean;
    break_start_time: string | null;
    break_end_time: string | null;
};

type TemplateRow = {
    id: string;
    name: string;
    days: DayRow[];
};

defineProps<{
    templates: TemplateRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Panel', href: dashboard() },
            { title: 'Plantillas de jornada', href: '#' },
        ],
    },
});

const DAY_LABELS = [
    'Domingo',
    'Lunes',
    'Martes',
    'Miércoles',
    'Jueves',
    'Viernes',
    'Sábado',
];

type DayFormRow = {
    day_of_week: number;
    start_time: string;
    end_time: string;
    crosses_midnight: boolean;
    break_start_time: string;
    break_end_time: string;
};

function newDayRow(): DayFormRow {
    return {
        day_of_week: 1,
        start_time: '06:00',
        end_time: '14:00',
        crosses_midnight: false,
        break_start_time: '',
        break_end_time: '',
    };
}

const days = ref<DayFormRow[]>([newDayRow()]);

function addDay() {
    days.value.push(newDayRow());
}

function removeDay(index: number) {
    days.value.splice(index, 1);
}
</script>

<template>
    <Head title="Plantillas de jornada" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Plantillas de jornada"
            description="Reglas de horario reutilizables por día de la semana"
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
                        <th class="p-3 font-medium">Días configurados</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="template in templates"
                        :key="template.id"
                        class="border-b border-sidebar-border/40 last:border-0 dark:border-sidebar-border/40"
                    >
                        <td class="p-3">{{ template.name }}</td>
                        <td class="p-3">
                            <span
                                v-for="day in template.days"
                                :key="day.id"
                                class="mr-2 inline-block"
                            >
                                {{ DAY_LABELS[day.day_of_week] }}
                                {{ day.start_time }}–{{ day.end_time
                                }}<span v-if="day.crosses_midnight">
                                    (nocturno)</span
                                ><span
                                    v-if="day.break_start_time && day.break_end_time"
                                >
                                    (descanso {{ day.break_start_time }}–{{
                                        day.break_end_time
                                    }})</span
                                >
                            </span>
                        </td>
                    </tr>
                    <tr v-if="templates.length === 0">
                        <td class="p-3 text-muted-foreground" colspan="2">
                            Todavía no hay plantillas de jornada.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            class="max-w-2xl space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <Heading variant="small" title="Crear plantilla" />

            <Form
                v-bind="WorkScheduleTemplateController.store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label for="name">Nombre</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        placeholder="Turno panadería"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="space-y-3">
                    <div
                        v-for="(day, index) in days"
                        :key="index"
                        class="grid grid-cols-2 items-end gap-2 rounded-lg border border-sidebar-border/50 p-3 md:grid-cols-7"
                    >
                        <div class="grid gap-1">
                            <Label>Día</Label>
                            <select
                                v-model.number="day.day_of_week"
                                :name="`days[${index}][day_of_week]`"
                                class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                            >
                                <option
                                    v-for="(label, value) in DAY_LABELS"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1">
                            <Label>Inicio</Label>
                            <Input
                                v-model="day.start_time"
                                :name="`days[${index}][start_time]`"
                                type="time"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label>Fin</Label>
                            <Input
                                v-model="day.end_time"
                                :name="`days[${index}][end_time]`"
                                type="time"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label>Descanso inicio</Label>
                            <Input
                                v-model="day.break_start_time"
                                :name="`days[${index}][break_start_time]`"
                                type="time"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label>Descanso fin</Label>
                            <Input
                                v-model="day.break_end_time"
                                :name="`days[${index}][break_end_time]`"
                                type="time"
                            />
                        </div>
                        <div class="flex items-center gap-1">
                            <input
                                :id="`crosses-${index}`"
                                v-model="day.crosses_midnight"
                                :name="`days[${index}][crosses_midnight]`"
                                type="checkbox"
                            />
                            <Label :for="`crosses-${index}`" class="text-xs">
                                Cruza medianoche
                            </Label>
                        </div>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            :disabled="days.length === 1"
                            @click="removeDay(index)"
                        >
                            Quitar
                        </Button>
                    </div>

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        @click="addDay"
                    >
                        Agregar día
                    </Button>
                    <InputError :message="errors.days" />
                </div>

                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Crear plantilla
                </Button>
            </Form>
        </div>
    </div>
</template>

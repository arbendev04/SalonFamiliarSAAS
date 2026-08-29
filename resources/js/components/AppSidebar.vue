<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    CalendarClock,
    FolderGit2,
    LayoutGrid,
    PartyPopper,
    Scale,
    Users,
    Wallet,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as branchesIndex } from '@/routes/branches';
import { index as employeesIndex } from '@/routes/employees';
import { index as holidaysIndex } from '@/routes/holidays';
import { index as laborRulesIndex } from '@/routes/labor-rules';
import { index as payrollPeriodsIndex } from '@/routes/payroll/periods';
import { index as positionsIndex } from '@/routes/positions';
import { index as schedulesIndex } from '@/routes/schedules';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Panel',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Empleados',
        href: employeesIndex(),
        icon: Users,
    },
    {
        title: 'Sedes',
        href: branchesIndex(),
        icon: Building2,
    },
    {
        title: 'Cargos',
        href: positionsIndex(),
        icon: Briefcase,
    },
    {
        title: 'Plantillas de jornada',
        href: schedulesIndex(),
        icon: CalendarClock,
    },
    {
        title: 'Reglas laborales',
        href: laborRulesIndex(),
        icon: Scale,
    },
    {
        title: 'Festivos',
        href: holidaysIndex(),
        icon: PartyPopper,
    },
    {
        title: 'Nómina',
        href: payrollPeriodsIndex(),
        icon: Wallet,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repositorio',
        href: 'https://github.com/arbendev04/SalonFamiliarSAAS',
        icon: FolderGit2,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

import { Link } from '@inertiajs/react';
import {
    Briefcase,
    FileText,
    LayoutGrid,
    ListChecks,
    MailCheck,
    Mail,
    Send,
    UserRound,
} from 'lucide-react';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import ApplicationRuleController from '@/actions/App/Http/Controllers/ApplicationRuleController';
import CareerProfileController from '@/actions/App/Http/Controllers/CareerProfileController';
import EmailAccountController from '@/actions/App/Http/Controllers/EmailAccountController';
import JobPostingController from '@/actions/App/Http/Controllers/JobPostingController';
import ResumeController from '@/actions/App/Http/Controllers/ResumeController';
import ReviewController from '@/actions/App/Http/Controllers/ReviewController';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
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
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Jobs', href: JobPostingController.index(), icon: Briefcase },
    { title: 'Review drafts', href: ReviewController.index(), icon: MailCheck },
    { title: 'Applications', href: ApplicationController.index(), icon: Send },
    {
        title: 'Career profile',
        href: CareerProfileController.edit(),
        icon: UserRound,
    },
    { title: 'Resumes', href: ResumeController.index(), icon: FileText },
    {
        title: 'Email accounts',
        href: EmailAccountController.index(),
        icon: Mail,
    },
    {
        title: 'Rules',
        href: ApplicationRuleController.edit(),
        icon: ListChecks,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

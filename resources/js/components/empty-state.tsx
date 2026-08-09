import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Ghost } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';

interface EmptyStateProps {
    title: string;
    description: string;
    icon?: LucideIcon;
    action?: {
        label: string;
        href: string;
    };
}

export function EmptyState({
    title,
    description,
    icon = Ghost,
    action,
}: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center rounded-2xl border border-border bg-card p-12 text-center backdrop-blur-sm">
            <div className="relative mb-6">
                <div className="absolute inset-0 rounded-full bg-muted blur-2xl" />
                <div className="relative flex size-16 items-center justify-center rounded-2xl border border-border bg-muted shadow-2xl">
                    <Icon
                        iconNode={icon}
                        className="size-8 text-muted-foreground"
                    />
                </div>
            </div>
            <h3 className="mb-2 text-xl font-bold tracking-tight text-foreground uppercase">
                {title}
            </h3>
            <p className="mb-8 max-w-[280px] text-sm leading-relaxed text-muted-foreground">
                {description}
            </p>
            {action && (
                <Button
                    asChild
                    variant="outline"
                    className="h-9 border-border px-6 text-[10px] font-bold tracking-wider text-foreground uppercase transition-all hover:bg-muted active:scale-95"
                >
                    <Link href={action.href}>{action.label}</Link>
                </Button>
            )}
        </div>
    );
}

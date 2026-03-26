import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import { CheckIcon, ChevronRightIcon, CircleIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export function DropdownMenu({ ...props }) {
    return <DropdownMenuPrimitive.Root data-slot="dropdown-menu" {...props} />;
}

export function DropdownMenuTrigger({ ...props }) {
    return <DropdownMenuPrimitive.Trigger data-slot="dropdown-menu-trigger" {...props} />;
}

export function DropdownMenuPortal({ ...props }) {
    return <DropdownMenuPrimitive.Portal data-slot="dropdown-menu-portal" {...props} />;
}

export function DropdownMenuContent({ className, sideOffset = 4, ...props }) {
    return (
        <DropdownMenuPrimitive.Portal>
            <DropdownMenuPrimitive.Content
                data-slot="dropdown-menu-content"
                sideOffset={sideOffset}
                className={cn(
                    'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 min-w-[8rem] overflow-hidden rounded-md border p-1 shadow-md',
                    className,
                )}
                {...props}
            />
        </DropdownMenuPrimitive.Portal>
    );
}

export function DropdownMenuGroup({ ...props }) {
    return <DropdownMenuPrimitive.Group data-slot="dropdown-menu-group" {...props} />;
}

export function DropdownMenuItem({ className, inset, variant = 'default', ...props }) {
    return (
        <DropdownMenuPrimitive.Item
            data-slot="dropdown-menu-item"
            data-inset={inset}
            data-variant={variant}
            className={cn(
                "focus:bg-accent focus:text-accent-foreground [&_svg:not([class*='text-'])]:text-muted-foreground relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[inset]:pl-8 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
                variant === 'destructive' && 'text-destructive-foreground focus:bg-destructive/10',
                className,
            )}
            {...props}
        />
    );
}

export function DropdownMenuLabel({ className, inset, ...props }) {
    return (
        <DropdownMenuPrimitive.Label
            data-slot="dropdown-menu-label"
            data-inset={inset}
            className={cn('px-2 py-1.5 text-sm font-medium data-[inset]:pl-8', className)}
            {...props}
        />
    );
}

export function DropdownMenuSeparator({ className, ...props }) {
    return (
        <DropdownMenuPrimitive.Separator
            data-slot="dropdown-menu-separator"
            className={cn('bg-border -mx-1 my-1 h-px', className)}
            {...props}
        />
    );
}

export function DropdownMenuSub({ ...props }) {
    return <DropdownMenuPrimitive.Sub data-slot="dropdown-menu-sub" {...props} />;
}

export function DropdownMenuSubTrigger({ className, inset, children, ...props }) {
    return (
        <DropdownMenuPrimitive.SubTrigger
            data-slot="dropdown-menu-sub-trigger"
            data-inset={inset}
            className={cn(
                'focus:bg-accent data-[state=open]:bg-accent flex cursor-default items-center rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[inset]:pl-8',
                className,
            )}
            {...props}
        >
            {children}
            <ChevronRightIcon className="ml-auto size-4" />
        </DropdownMenuPrimitive.SubTrigger>
    );
}

export function DropdownMenuSubContent({ className, ...props }) {
    return (
        <DropdownMenuPrimitive.SubContent
            data-slot="dropdown-menu-sub-content"
            className={cn(
                'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 z-50 min-w-[8rem] overflow-hidden rounded-md border p-1 shadow-lg',
                className,
            )}
            {...props}
        />
    );
}

export function DropdownMenuShortcut({ className, ...props }) {
    return (
        <span
            data-slot="dropdown-menu-shortcut"
            className={cn('text-muted-foreground ml-auto text-xs tracking-widest', className)}
            {...props}
        />
    );
}

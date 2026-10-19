import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

/**
 * A right-click menu, drawn where the pointer is.
 *
 * `items` is a list of `{ label, icon, onSelect, destructive }`, with
 * `{ divider: true }` between groups; falsy entries are skipped so callers can
 * write `condition && { … }` inline. Portalled to <body> so a table's
 * overflow cannot clip it, and pulled back inside the viewport when the
 * pointer is near an edge.
 *
 * Closes on a click elsewhere, Escape, scroll and resize. Arrow keys move
 * through the items and Enter picks one, so a keyboard user who opened it
 * with the Menu key is not stranded in it.
 */
export function ContextMenu({ open, x, y, items = [], onClose, label = 'Actions' }) {
    const menuRef = useRef(null);
    const [position, setPosition] = useState({ left: x, top: y });
    const entries = items.filter(Boolean);

    useLayoutEffect(() => {
        if (!open || !menuRef.current) return;

        const { width, height } = menuRef.current.getBoundingClientRect();
        const margin = 8;

        setPosition({
            left: Math.max(margin, Math.min(x, window.innerWidth - width - margin)),
            top: Math.max(margin, Math.min(y, window.innerHeight - height - margin)),
        });

        menuRef.current.querySelector('[role="menuitem"]')?.focus();
    }, [open, x, y]);

    useEffect(() => {
        if (!open) return;

        const close = () => onClose?.();
        const outside = (event) => {
            if (!menuRef.current?.contains(event.target)) close();
        };

        // A right-click on another row has already opened the menu there
        // (and prevented the browser's own); closing it here would undo that.
        const outsideRightClick = (event) => {
            if (!event.defaultPrevented) outside(event);
        };
        const outsidePress = (event) => {
            if (event.button !== 2) outside(event);
        };

        document.addEventListener('mousedown', outsidePress);
        document.addEventListener('contextmenu', outsideRightClick);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);

        return () => {
            document.removeEventListener('mousedown', outsidePress);
            document.removeEventListener('contextmenu', outsideRightClick);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
    }, [open, onClose]);

    if (!open) return null;

    const onKeyDown = (event) => {
        const buttons = [...menuRef.current.querySelectorAll('[role="menuitem"]')];
        const index = buttons.indexOf(document.activeElement);

        if (event.key === 'Escape' || event.key === 'Tab') {
            event.preventDefault();
            onClose?.();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            buttons[(index + 1) % buttons.length]?.focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            buttons[(index - 1 + buttons.length) % buttons.length]?.focus();
        }
    };

    return createPortal(
        <div
            ref={menuRef}
            role="menu"
            aria-label={label}
            onKeyDown={onKeyDown}
            style={{ left: position.left, top: position.top }}
            className="fixed z-50 min-w-[14rem] overflow-hidden rounded-lg border border-border bg-popover py-1 text-popover-foreground shadow-lg"
        >
            {entries.map((item, index) =>
                item.divider ? (
                    <div
                        key={`divider-${index}`}
                        className="my-1 h-px bg-border"
                        role="separator"
                    />
                ) : (
                    <button
                        key={item.label}
                        type="button"
                        role="menuitem"
                        onClick={() => {
                            onClose?.();
                            item.onSelect?.();
                        }}
                        className={cn(
                            'flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition-colors focus:outline-none',
                            item.destructive
                                ? 'text-destructive hover:bg-destructive/10 focus:bg-destructive/10'
                                : 'text-foreground hover:bg-secondary focus:bg-secondary',
                        )}
                    >
                        {item.icon && (
                            <item.icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                        )}
                        {item.label}
                    </button>
                ),
            )}
        </div>,
        document.body,
    );
}

/**
 * State for one context menu shared by many rows: `openAt(event, row)` from an
 * `onContextMenu`, or from the Menu key / Shift+F10 on a focused row.
 */
export function useContextMenu() {
    const [menu, setMenu] = useState(null);

    const openAt = (event, target) => {
        event.preventDefault();

        if (event.type === 'contextmenu' && event.clientX + event.clientY > 0) {
            setMenu({ x: event.clientX, y: event.clientY, target });
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();
        setMenu({ x: rect.left + 24, y: rect.top + rect.height / 2, target });
    };

    const onRowKeyDown = (event, target) => {
        if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
            openAt(event, target);
        }
    };

    return { menu, openAt, onRowKeyDown, close: () => setMenu(null) };
}

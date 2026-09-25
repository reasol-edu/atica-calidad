import { Controller } from '@hotwired/stimulus';

const MIN_WIDTH = 200; // px
const MAX_WIDTH = 420; // px
const DEFAULT_WIDTH = 288; // px (18rem)
const STORAGE_KEY = 'aticacalidad:document-tree-sidebar';

// The document tree's sidebar (desktop only): a gutter between it and the main content resizes it
// by dragging, and a button on that same gutter — always there, whichever side is collapsed —
// hides it entirely or brings it back. Both the width and the collapsed state are remembered in
// localStorage (not tied to a centre: it's a personal screen preference, not centre data).
export default class extends Controller {
    static targets = ['container', 'aside', 'icon'];

    connect() {
        const saved = this.read();
        this.width = saved.width ?? DEFAULT_WIDTH;
        this.collapsed = saved.collapsed ?? false;
        this.apply();

        this.onDrag = this.drag.bind(this);
        this.onStopDrag = this.stopDrag.bind(this);
    }

    disconnect() {
        window.removeEventListener('mousemove', this.onDrag);
        window.removeEventListener('mouseup', this.onStopDrag);
    }

    startDrag(event) {
        if (this.collapsed) {
            return;
        }
        event.preventDefault();
        this.dragStartX = event.clientX;
        this.dragStartWidth = this.width;
        window.addEventListener('mousemove', this.onDrag);
        window.addEventListener('mouseup', this.onStopDrag);
        document.body.classList.add('cursor-col-resize', 'select-none');
    }

    drag(event) {
        const width = this.dragStartWidth + (event.clientX - this.dragStartX);
        this.width = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, width));
        this.apply();
    }

    stopDrag() {
        window.removeEventListener('mousemove', this.onDrag);
        window.removeEventListener('mouseup', this.onStopDrag);
        document.body.classList.remove('cursor-col-resize', 'select-none');
        this.persist();
    }

    toggle() {
        this.collapsed = !this.collapsed;
        this.apply();
        this.persist();
    }

    apply() {
        this.containerTarget.style.setProperty('--tree-sidebar-width', this.collapsed ? '0px' : `${this.width}px`);
        this.asideTarget.classList.toggle('invisible', this.collapsed);
        this.asideTarget.toggleAttribute('inert', this.collapsed);
        if (this.hasIconTarget) {
            this.iconTarget.classList.toggle('rotate-180', this.collapsed);
        }
    }

    persist() {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ width: this.width, collapsed: this.collapsed }));
        } catch {
            // localStorage not available (private mode, quota...): the choice just won't stick.
        }
    }

    /** @return {{width?: number, collapsed?: boolean}} */
    read() {
        try {
            const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}');
            return {
                width: typeof parsed.width === 'number' ? Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, parsed.width)) : undefined,
                collapsed: typeof parsed.collapsed === 'boolean' ? parsed.collapsed : undefined,
            };
        } catch {
            return {};
        }
    }
}

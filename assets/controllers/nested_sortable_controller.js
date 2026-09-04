import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import Sortable from 'sortablejs';

/*
 * Turns every nested `<ul data-parent-id>` inside the controller's element into a SortableJS list
 * sharing one group, so a node can be dragged to reorder within its level or dropped into another
 * node's list to reparent it, in a single interaction. Re-scans on every Live Component re-render
 * (`live:render`) to pick up lists that didn't exist yet (e.g. a newly added child's own, still-
 * empty, list) — already-initialised lists are skipped via a flag kept on the element itself,
 * since morphdom reuses matching DOM nodes across renders.
 *
 * Generic over which entity is being reordered (document sections, list items...) via three
 * Stimulus values, so the same controller drives every nested tree editor instead of one
 * hand-copied file per entity:
 * - idAttr: the camelCase dataset key each draggable <li> carries its id under
 *   (e.g. "sectionId" for a `data-section-id` attribute).
 * - moveAction: the LiveAction to call on drop — must accept (id, newParentId, orderedIds).
 * - group: the SortableJS group name. Give each independent tree its own so items could never be
 *   dragged from one into the other, even if two instances of this controller ever ended up on
 *   the same page.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        idAttr: String,
        moveAction: String,
        group: String,
    };

    connect() {
        this.onRender = () => this.initSortables();
        this.element.addEventListener('live:render', this.onRender);
        this.initSortables();
    }

    disconnect() {
        this.element.removeEventListener('live:render', this.onRender);
        this.element.querySelectorAll('[data-parent-id]').forEach((list) => {
            list.sortableInstance?.destroy();
            delete list.sortableInstance;
        });
    }

    initSortables() {
        this.element.querySelectorAll('[data-parent-id]').forEach((list) => {
            if (list.sortableInstance) {
                return;
            }
            list.sortableInstance = new Sortable(list, {
                group: this.groupValue,
                handle: '.js-drag-handle',
                animation: 150,
                fallbackOnBody: true,
                swapThreshold: 0.65,
                onEnd: (event) => this.onDrop(event),
            });
        });
    }

    async onDrop(event) {
        const item = event.item;
        const toList = event.to;
        const id = item.dataset[this.idAttrValue];
        if (!id) {
            return;
        }

        const newParentId = toList.dataset.parentId ?? '';
        const orderedIds = Array.from(toList.children)
            .map((el) => el.dataset[this.idAttrValue])
            .filter((entityId) => Boolean(entityId));

        const component = await getComponent(this.element);
        component.action(this.moveActionValue, { id, newParentId, orderedIds });
    }
}

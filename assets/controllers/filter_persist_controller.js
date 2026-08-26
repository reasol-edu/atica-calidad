import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// Defaults del StayListComponent (retrocompatibilidad cuando no se pasa 'defaults').
const STAY_DEFAULTS = {
    search: '',
    familyId: '',
    programmeId: '',
    showCurrent: true,
    showFuture: true,
    showPast: true,
};

export default class extends Controller {
    static values = {
        centreId: String,
        active: Boolean,
        key: { type: String, default: 'stay-filters' },
        defaults: { type: Object, default: null },
    };

    get effectiveDefaults() {
        const d = this.defaultsValue;
        return d !== null && Object.keys(d).length > 0 ? d : STAY_DEFAULTS;
    }

    async connect() {
        this.onRender = () => this.persist();
        this.element.addEventListener('live:render', this.onRender);

        if (this.activeValue) {
            return;
        }

        const saved = this.read();
        if (saved === null) {
            return;
        }

        const component = await getComponent(this.element);
        const defaults = this.effectiveDefaults;
        let changed = false;
        for (const [prop, value] of Object.entries(saved)) {
            if (value !== defaults[prop]) {
                component.set(prop, value, false);
                changed = true;
            }
        }
        if (changed) {
            component.render();
        }
    }

    disconnect() {
        this.element.removeEventListener('live:render', this.onRender);
    }

    persist() {
        const state = this.currentState();
        if (state === null) {
            return;
        }

        const defaults = this.effectiveDefaults;
        const isClean = Object.entries(defaults).every(([prop, def]) => state[prop] === def);
        try {
            if (isClean) {
                window.localStorage.removeItem(this.storageKey());
            } else {
                window.localStorage.setItem(this.storageKey(), JSON.stringify(state));
            }
        } catch {
            // localStorage no disponible (modo privado, cuota...): se ignora
        }
    }

    currentState() {
        let props;
        try {
            props = JSON.parse(this.element.dataset.livePropsValue ?? '{}');
        } catch {
            return null;
        }

        return this.sanitize(props);
    }

    read() {
        let raw;
        try {
            raw = window.localStorage.getItem(this.storageKey());
        } catch {
            return null;
        }
        if (raw === null) {
            return null;
        }

        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch {
            return null;
        }
        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }

        return this.sanitize(parsed);
    }

    sanitize(data) {
        const defaults = this.effectiveDefaults;
        const state = { ...defaults };

        for (const [prop, def] of Object.entries(defaults)) {
            const incoming = data[prop];
            if (typeof def === 'string' && typeof incoming === 'string') {
                state[prop] = incoming.slice(0, 255);
            } else if (typeof def === 'boolean' && typeof incoming === 'boolean') {
                state[prop] = incoming;
            }
        }

        return state;
    }

    storageKey() {
        return `gestconvplus:${this.keyValue}:${this.centreIdValue}`;
    }
}

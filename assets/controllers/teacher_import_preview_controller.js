import { Controller } from '@hotwired/stimulus';

// Vista previa de la importación de docentes: marcar/desmarcar todo, contador de seleccionados,
// filas que solo cambiarían el correo (se desactivan si no se importa el correo) y panel con los
// docentes que se retirarían del curso (solo se envían si la opción está marcada).
export default class extends Controller {
    static targets = ['teacher', 'remove', 'optEmail', 'optRemove', 'removePanel', 'counter'];
    static values = { selectedLabel: String };

    connect() {
        this.refreshEmailOption();
        this.refreshRemoveOption();
    }

    selectAll(event) {
        this.setGroup(event.params.group, true);
    }

    selectNone(event) {
        this.setGroup(event.params.group, false);
    }

    refreshCount() {
        // Total = filas con algo que hacer: las «sin cambios» (y las de solo correo con la opción apagada) están desactivadas.
        const actionable = this.teacherTargets.filter((box) => !box.disabled);
        const on = actionable.filter((box) => box.checked).length;
        this.counterTarget.textContent = this.selectedLabelValue
            .replace('%selected%', on)
            .replace('%total%', actionable.length);
    }

    refreshEmailOption() {
        const importEmail = this.hasOptEmailTarget && this.optEmailTarget.checked;
        this.teacherTargets.filter((box) => box.dataset.emailOnly === '1').forEach((box) => {
            const row = box.closest('tr');
            if (!importEmail) {
                box.dataset.was = box.checked ? '1' : '0';
                box.checked = false;
                box.disabled = true;
                row.classList.add('text-gray-400');
            } else {
                box.disabled = false;
                box.checked = box.dataset.was !== '0';
                row.classList.remove('text-gray-400');
            }
        });
        this.refreshCount();
    }

    refreshRemoveOption() {
        const on = this.optRemoveTarget.checked;
        this.removePanelTarget.classList.toggle('hidden', !on);
        this.removeTargets.forEach((box) => {
            box.disabled = !on;
        });
    }

    setGroup(group, checked) {
        const boxes = group === 'remove' ? this.removeTargets : this.teacherTargets;
        boxes.filter((box) => !box.disabled).forEach((box) => {
            box.checked = checked;
        });
        this.refreshCount();
    }
}

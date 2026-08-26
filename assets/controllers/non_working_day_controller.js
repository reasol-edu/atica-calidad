import { Controller } from '@hotwired/stimulus';
import { isNonWorkingDate } from '../utils/non_working_days.js';

// Bloquea la selección de fechas no lectivas (fin de semana o festivo
// declarado) en uno o varios <input type="date">: revierte al último valor
// válido y muestra un aviso junto al campo. Los targets "input" y "warning"
// se emparejan por posición en el DOM (el enésimo "warning" corresponde al
// enésimo "input"). Uso: data-controller="non-working-day" en un contenedor
// con, para cada fecha, un <input data-non-working-day-target="input"
// data-action="change->non-working-day#check"> seguido de un
// <p data-non-working-day-target="warning">, y
// data-non-working-day-dates-value="[...]" (fechas ISO de festivos del curso).
export default class extends Controller {
    static targets = ['input', 'warning'];
    static values  = { dates: Array, blockedMessage: String };

    connect() {
        this.lastValid = this.inputTargets.map((input) => input.value);
    }

    check(event) {
        const index = this.inputTargets.indexOf(event.target);
        const input   = this.inputTargets[index];
        const warning = this.warningTargets[index];

        if (!this.isInvalid(input.value)) {
            this.lastValid[index] = input.value;
            this.setWarning(input, warning, false);
            return;
        }

        input.value = this.lastValid[index] ?? '';
        this.setWarning(input, warning, true);
    }

    isInvalid(value) {
        return value !== '' && isNonWorkingDate(value, this.datesValue);
    }

    setWarning(input, warning, active) {
        warning.textContent = active ? this.blockedMessageValue : '';
        warning.classList.toggle('hidden', !active);
        input.classList.toggle('border-red-300', active);
    }
}

// Utilidades de fechas no lectivas (fin de semana o festivo declarado) para
// controladores Stimulus. Mismo contrato conceptual que el servicio PHP
// App\Service\NonWorkingDayChecker, pero en JS puro y sin dependencias.
// Las fechas se manejan siempre como cadenas ISO ('YYYY-MM-DD') y se parsean
// en UTC para evitar desfases por zona horaria/horario de verano.

function parseISODate(iso) {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d));
}

function formatISODate(date) {
    return date.toISOString().slice(0, 10);
}

function addDays(date, days) {
    return new Date(date.getTime() + days * 86400000);
}

function isWeekend(date) {
    const day = date.getUTCDay();

    return day === 0 || day === 6;
}

export function isNonWorkingDate(iso, nonWorkingDates) {
    return isWeekend(parseISODate(iso)) || nonWorkingDates.includes(iso);
}

export function countSchoolDays(fromIso, toIso, nonWorkingDates) {
    let cursor = parseISODate(fromIso);
    const end  = parseISODate(toIso);

    let count = 0;
    while (cursor <= end) {
        if (!isNonWorkingDate(formatISODate(cursor), nonWorkingDates)) {
            count++;
        }
        cursor = addDays(cursor, 1);
    }

    return count;
}

export function addSchoolDays(fromIso, schoolDays, nonWorkingDates) {
    let remaining = schoolDays;
    let cursor    = parseISODate(fromIso);

    while (true) {
        if (!isNonWorkingDate(formatISODate(cursor), nonWorkingDates)) {
            remaining--;
            if (remaining <= 0) {
                return formatISODate(cursor);
            }
        }
        cursor = addDays(cursor, 1);
    }
}

// Non-working date (weekends or declared holidays) utilities for Stimulus
// controllers. Same conceptual contract as the PHP service
// App\Service\NonWorkingDayChecker, but in plain JS with no dependencies.
// Dates are always handled as ISO strings ('YYYY-MM-DD') and parsed
// in UTC to avoid offsets from timezone/daylight saving time.

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

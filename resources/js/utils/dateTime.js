const BUSINESS_TIME_ZONE = 'America/Lima';

const parseInstant = (value) => {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
};

export const formatDateTime = (value, fallback = '—') => {
    const date = parseInstant(value);
    if (!date) return fallback;

    return new Intl.DateTimeFormat('es-PE', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: BUSINESS_TIME_ZONE,
    }).format(date);
};

export const formatCalendarDate = (value, fallback = '') => {
    if (!value) return fallback;
    const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T12:00:00-05:00` : value;
    const date = parseInstant(dateOnly);
    if (!date) return fallback;

    return new Intl.DateTimeFormat('es-PE', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: BUSINESS_TIME_ZONE,
    }).format(date);
};

export { BUSINESS_TIME_ZONE };

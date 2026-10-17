const dayInMilliseconds = 24 * 60 * 60 * 1000;

function calendarDay(date: Date, timezone: string): number {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(date);
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        Number(parts.find((item) => item.type === type)?.value);

    return Date.UTC(part('year'), part('month') - 1, part('day'));
}

export function formatMessageDate(
    value: string | null | undefined,
    now: Date,
    timezone: string,
): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const daysAgo =
        (calendarDay(now, timezone) - calendarDay(date, timezone)) /
        dayInMilliseconds;

    if (daysAgo === 0) {
        return new Intl.DateTimeFormat('pt-BR', {
            timeZone: timezone,
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).format(date);
    }

    if (daysAgo === 1) {
        return 'ontem';
    }

    if (daysAgo >= 2 && daysAgo <= 6) {
        return new Intl.DateTimeFormat('pt-BR', {
            timeZone: timezone,
            weekday: 'long',
        })
            .format(date)
            .replace(/-feira$/, '');
    }

    return new Intl.DateTimeFormat('pt-BR', {
        timeZone: timezone,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
}

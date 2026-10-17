import assert from 'node:assert/strict';
import test from 'node:test';
import { formatMessageDate } from '../../resources/js/lib/messageDate.ts';

const timezone = 'America/Sao_Paulo';
const now = new Date('2026-10-17T15:00:00Z');

test('shows time today and relative dates through six calendar days', () => {
    assert.equal(
        formatMessageDate('2026-10-17T12:34:00Z', now, timezone),
        '09:34',
    );
    assert.equal(
        formatMessageDate('2026-10-16T12:00:00Z', now, timezone),
        'ontem',
    );
    assert.equal(
        formatMessageDate('2026-10-15T12:00:00Z', now, timezone),
        'quinta',
    );
    assert.equal(
        formatMessageDate('2026-10-11T12:00:00Z', now, timezone),
        'domingo',
    );
    assert.equal(
        formatMessageDate('2026-10-10T12:00:00Z', now, timezone),
        '10/10/2026',
    );
});

test('uses workspace calendar dates across midnight regardless of browser timezone', () => {
    const nearMidnight = new Date('2026-10-17T02:30:00Z');
    const message = '2026-10-16T23:30:00Z';

    assert.equal(formatMessageDate(message, nearMidnight, timezone), '20:30');
    assert.equal(formatMessageDate(message, nearMidnight, 'UTC'), 'ontem');
    assert.equal(
        formatMessageDate(message, new Date('2026-10-17T03:01:00Z'), timezone),
        'ontem',
    );
});

test('empty and invalid timestamps have no label', () => {
    assert.equal(formatMessageDate(null, now, timezone), '');
    assert.equal(formatMessageDate('invalid', now, timezone), '');
});

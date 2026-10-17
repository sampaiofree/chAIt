import assert from 'node:assert/strict';
import test from 'node:test';
import { mergeChatMessages, nextMessageStatus } from '../../resources/js/lib/chatState.ts';

test('history and a concurrent live event reconcile by provider ID even if only one has track_id', () => {
    const result = mergeChatMessages([{ id: 'provider-1', text: 'snapshot', status: 'sent' }], [{ id: 'provider-1', track_id: 'intent-1', text: 'live', status: 'delivered' }]);
    assert.equal(result.length, 1);
    assert.equal(result[0].text, 'live');
    assert.equal(result[0].status, 'delivered');
});
test('an optimistic message is replaced by its provider identity without duplicating it', () => {
    const result = mergeChatMessages([{ id: 'intent-1', track_id: 'intent-1', status: 'pending' }], [{ id: 'provider-1', track_id: 'intent-1', status: 'sent' }]);
    assert.equal(result.length, 1);
    assert.equal(result[0].id, 'provider-1');
});
test('old snapshots cannot regress read and played receipts', () => {
    assert.equal(nextMessageStatus('read', 'delivered'), 'read');
    assert.equal(nextMessageStatus('played', 'sent'), 'played');
    assert.equal(nextMessageStatus('sent', 'read'), 'read');
    assert.equal(mergeChatMessages([{ id: '1', status: 'read' }], [{ id: '1', status: 'sent' }])[0].status, 'read');
});
test('out of order live delivery is sorted by message timestamp', () => {
    assert.deepEqual(mergeChatMessages([{ id: 'new', timestamp: '2026-10-16T12:01:00Z' }], [{ id: 'old', timestamp: '2026-10-16T12:00:00Z' }]).map((message) => message.id), ['old', 'new']);
});
test('a failed send is visible but cannot overwrite proof of delivery or reading', () => {
    assert.equal(nextMessageStatus('pending', 'failed'), 'failed');
    assert.equal(nextMessageStatus('read', 'failed'), 'read');
    assert.equal(nextMessageStatus('failed', 'pending'), 'failed');
});

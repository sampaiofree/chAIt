import http from 'node:http';
import https from 'node:https';
import { appendFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

// Expose only the UAZAPI receiver while a temporary Cloudflare Tunnel is active.
// Run with NODE_EXTRA_CA_CERTS pointing to Herd's local CA certificate.
const port = Number(process.env.CHAIT_WEBHOOK_PROXY_PORT || 8877);
const webhookPath = /^\/webhooks\/uazapi\/[1-9][0-9]*\/[a-f0-9]{64}$/;
const maxBodyBytes = 262144;
const evidenceFile = new URL('../../storage/app/private/homologation-webhooks.jsonl', import.meta.url);
const digest = (value) => typeof value === 'string' && value !== ''
    ? createHash('sha256').update(value).digest('hex') : null;

function metadata(body, status) {
    try {
        const payload = JSON.parse(body.toString('utf8'));
        const message = payload.message || {};
        return {
            at: new Date().toISOString(),
            event_type: payload.EventType || null,
            message_type: message.messageType || message.type || null,
            message_id_hash: digest(message.messageid || message.id || message.key?.id),
            chat_id_hash: digest(message.chatid || message.key?.remoteJid),
            selection_id_hash: digest(message.buttonOrListid),
            from_me: message.fromMe ?? message.key?.fromMe ?? null,
            status,
        };
    } catch {
        return { at: new Date().toISOString(), event_type: null, status };
    }
}

http.createServer((request, response) => {
    const pathname = new URL(request.url || '/', 'http://localhost').pathname;
    if (request.method !== 'POST' || !webhookPath.test(pathname)) {
        request.resume();
        response.writeHead(404).end();
        return;
    }

    const size = Number(request.headers['content-length']);
    if (Number.isFinite(size) && size > maxBodyBytes) {
        request.resume();
        response.writeHead(413).end();
        return;
    }

    let received = 0;
    const chunks = [];
    const headers = {
        'content-type': request.headers['content-type'] || 'application/json',
        host: 'chait.test',
        'x-forwarded-proto': 'https',
    };
    if (request.headers['content-length']) {
        headers['content-length'] = request.headers['content-length'];
    }
    const upstream = https.request({
        hostname: 'chait.test',
        port: 443,
        path: pathname,
        method: 'POST',
        headers,
    }, (upstreamResponse) => {
        appendFileSync(evidenceFile, JSON.stringify(metadata(Buffer.concat(chunks), upstreamResponse.statusCode)) + '\n', { mode: 0o600 });
        response.writeHead(upstreamResponse.statusCode || 502, {
            'content-type': upstreamResponse.headers['content-type'] || 'application/json',
        });
        upstreamResponse.pipe(response);
    });

    upstream.on('error', () => {
        if (!response.headersSent) response.writeHead(502);
        response.end();
    });
    request.on('data', (chunk) => {
        received += chunk.length;
        if (received > maxBodyBytes) {
            upstream.destroy();
            if (!response.headersSent) response.writeHead(413);
            response.end();
            request.destroy();
            return;
        }
        chunks.push(chunk);
        upstream.write(chunk);
    });
    request.on('end', () => upstream.end());
}).listen(port, '127.0.0.1', () => {
    process.stdout.write(`UAZAPI webhook proxy listening on 127.0.0.1:${port}\n`);
});

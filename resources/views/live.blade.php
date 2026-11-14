<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Live notification status</title>
    <style>
        :root { color-scheme: light dark; --line: #8884; --muted: #888; }
        body { font: 14px/1.5 system-ui, sans-serif; margin: 0; padding: 24px; max-width: 1100px; margin-inline: auto; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        p { margin: 0 0 16px; color: var(--muted); }
        code { font: 12px ui-monospace, monospace; }
        .bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
        .pill { border: 1px solid var(--line); border-radius: 999px; padding: 2px 10px; font-variant-numeric: tabular-nums; }
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: #c33; margin-right: 6px; }
        .dot.on { background: #2a2; }
        input { font: inherit; padding: 4px 8px; border: 1px solid var(--line); border-radius: 6px; width: 320px; background: transparent; color: inherit; }
        button { font: inherit; padding: 4px 12px; border: 1px solid var(--line); border-radius: 6px; background: transparent; color: inherit; cursor: pointer; }
        table { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums; }
        th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
        td.detail { white-space: normal; color: var(--muted); }
        .sent { color: #2a2; } .failed { color: #c33; } .retrying { color: #c80; } .cancelled, .queued { color: var(--muted); }
    </style>
</head>
<body>
    <h1>Live notification status</h1>
    <p>
        Every status change is pushed over WebSocket (Laravel Reverb, Pusher protocol).
        This page uses the browser's plain <code>WebSocket</code>, no client library.
    </p>

    <div class="bar">
        <span class="pill"><span id="dot" class="dot"></span><span id="state">connecting</span></span>
        <span class="pill">channel <code id="channel">notifications</code></span>
        <span class="pill sent">sent <b id="n-sent">0</b></span>
        <span class="pill retrying">retrying <b id="n-retrying">0</b></span>
        <span class="pill failed">failed <b id="n-failed">0</b></span>
        <span class="pill cancelled">cancelled <b id="n-cancelled">0</b></span>
    </div>

    <div class="bar">
        <input id="filter" placeholder="Follow one notification or batch: paste its id">
        <button id="follow-notification">Follow notification</button>
        <button id="follow-batch">Follow batch</button>
        <button id="follow-all">All</button>
    </div>

    <table>
        <thead><tr><th>Time (UTC)</th><th>Notification</th><th>Channel</th><th>Status</th><th>Attempts</th><th>Detail</th></tr></thead>
        <tbody id="rows"></tbody>
    </table>

    <script>
        const key = @json($key);
        const url = `ws://${location.hostname}:{{ $port }}/app/${key}?protocol=7&client=js&version=8.4.0&flash=false`;
        const rows = document.getElementById('rows');
        const counts = { sent: 0, retrying: 0, failed: 0, cancelled: 0 };
        let socket, channel = 'notifications';

        function show(state, on) {
            document.getElementById('state').textContent = state;
            document.getElementById('dot').classList.toggle('on', on);
        }

        function subscribe(next) {
            if (socket?.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({ event: 'pusher:unsubscribe', data: { channel } }));
                socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: next } }));
            }
            channel = next;
            document.getElementById('channel').textContent = next;
        }

        function add(update) {
            if (update.status in counts) {
                counts[update.status]++;
                document.getElementById(`n-${update.status}`).textContent = counts[update.status];
            }

            const row = rows.insertRow(0);
            for (const [text, cls] of [
                [update.at.slice(11, 23), ''], [update.id, ''], [update.channel, ''],
                [update.status, update.status], [update.attempts, ''], [update.detail ?? '', 'detail'],
            ]) {
                const cell = row.insertCell();
                cell.textContent = text;
                cell.className = cls;
            }
            while (rows.rows.length > 300) rows.deleteRow(-1);
        }

        function connect() {
            socket = new WebSocket(url);
            socket.onopen = () => show('connected', true);
            socket.onclose = () => { show('disconnected, retrying', false); setTimeout(connect, 2000); };
            socket.onmessage = ({ data }) => {
                const message = JSON.parse(data);
                if (message.event === 'pusher:connection_established') subscribe(channel);
                if (message.event === 'pusher:ping') socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                if (message.event === 'notification.status') add(JSON.parse(message.data));
            };
        }

        const id = () => document.getElementById('filter').value.trim();
        document.getElementById('follow-notification').onclick = () => id() && subscribe(`notifications.${id()}`);
        document.getElementById('follow-batch').onclick = () => id() && subscribe(`batches.${id()}`);
        document.getElementById('follow-all').onclick = () => subscribe('notifications');

        connect();
    </script>
</body>
</html>

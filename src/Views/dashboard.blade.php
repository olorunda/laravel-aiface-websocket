<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AiFace WebSocket Terminal & Command Console</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #0b0f19;
            --bg-card: #121929;
            --bg-input: #1a233a;
            --border: #232f48;
            --text-primary: #f1f5f9;
            --text-muted: #94a3b8;
            --accent-cyan: #06b6d4;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-yellow: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-primary);
            line-height: 1.5;
            padding: 24px;
        }

        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid var(--border); padding-bottom: 20px; margin-bottom: 24px;
        }
        .header-title h1 {
            font-size: 24px; font-weight: 700;
            background: linear-gradient(135deg, #38bdf8, #818cf8);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .header-title p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        
        .badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
            border-radius: 9999px; font-size: 12px; font-weight: 500;
        }
        .badge-online { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-offline { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }

        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 1024px) { .grid { grid-template-columns: 1fr; } }

        .card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 12px;
        }
        .card-header h2 { font-size: 16px; font-weight: 600; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 10px; color: var(--text-muted); font-weight: 500; border-bottom: 1px solid var(--border); }
        td { padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.04); }

        .btn {
            background: var(--accent-blue); color: #fff; border: none; padding: 8px 14px;
            border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn:hover { filter: brightness(1.15); }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
        .btn-green { background: var(--accent-green); }
        .btn-yellow { background: var(--accent-yellow); color: #111; }
        .btn-red { background: var(--accent-red); }
        .btn-secondary { background: var(--bg-input); border: 1px solid var(--border); color: var(--text-primary); }

        .form-group { margin-bottom: 14px; }
        label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 6px; font-weight: 500; }
        select, input, textarea {
            width: 100%; background: var(--bg-input); border: 1px solid var(--border);
            color: var(--text-primary); padding: 9px 12px; border-radius: 6px; font-size: 13px;
            outline: none; font-family: inherit;
        }
        select:focus, input:focus, textarea:focus { border-color: var(--accent-blue); }
        textarea { font-family: 'JetBrains Mono', monospace; font-size: 12px; min-height: 120px; }

        .console-output {
            background: #050811; border: 1px solid var(--border); border-radius: 6px;
            padding: 12px; font-family: 'JetBrains Mono', monospace; font-size: 12px;
            color: #38bdf8; min-height: 160px; max-height: 280px; overflow-y: auto; white-space: pre-wrap;
        }

        .pulse {
            width: 8px; height: 8px; border-radius: 50%; display: inline-block;
            background: var(--accent-green); box-shadow: 0 0 8px var(--accent-green);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <h1>AiFace WebSocket Control Center</h1>
                <p>Real-time Biometric Terminal Daemon & Device Command Interpreter</p>
            </div>
            <div style="text-align: right;">
                <div style="margin-bottom: 6px;">
                    <span class="badge badge-online"><span class="pulse"></span> WS Server: Port {{ $serverPort }}{{ $serverPath }}</span>
                </div>
                <span style="font-size: 12px; color: var(--text-muted);">Local IPC Active · {{ count($onlineDevices) }} Device(s) Online</span>
            </div>
        </div>

        <!-- Upper Grid: Online Devices & Command Runner -->
        <div class="grid">
            <!-- Devices Card -->
            <div class="card">
                <div class="card-header">
                    <h2>Biometric Hardware Devices</h2>
                    <button class="btn btn-secondary btn-sm" onclick="location.reload()">Refresh</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th>Status</th>
                            <th>Model / IP</th>
                            <th>Users / Logs</th>
                            <th>Quick Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($dbDevices as $dev)
                            @php $isOnline = isset($onlineDevices[$dev->sn]); @endphp
                            <tr>
                                <td><strong style="color: #38bdf8;">{{ $dev->sn }}</strong></td>
                                <td>
                                    <span class="badge {{ $isOnline ? 'badge-online' : 'badge-offline' }}">
                                        {{ $isOnline ? 'Online' : 'Offline' }}
                                    </span>
                                </td>
                                <td>
                                    <div>{{ $dev->model ?? 'AiFace' }}</div>
                                    <small style="color: var(--text-muted);">{{ $isOnline ? $onlineDevices[$dev->sn]['ip'] : $dev->ip }}</small>
                                </td>
                                <td>
                                    <small>{{ $dev->useduser }} Users · {{ $dev->usedlog }} Logs</small>
                                </td>
                                <td>
                                    <button class="btn btn-green btn-sm" onclick="quickAction('{{ $dev->sn }}', 'opendoor', {doornum: 1})">Unlock</button>
                                    <button class="btn btn-secondary btn-sm" onclick="quickAction('{{ $dev->sn }}', 'sync-time')">Sync Time</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                    No devices registered yet. Connect your AiFace hardware to:
                                    <br><code style="color: #38bdf8;">ws://{{ request()->getHost() }}:{{ $serverPort }}{{ $serverPath }}</code>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Command Console -->
            <div class="card">
                <div class="card-header">
                    <h2>Interactive Device Command Console</h2>
                    <span style="font-size: 12px; color: var(--text-muted);">80+ Commands Available</span>
                </div>

                <div class="form-group">
                    <label>Target Device (Serial Number)</label>
                    <select id="targetSn">
                        @foreach($dbDevices as $dev)
                            <option value="{{ $dev->sn }}">{{ $dev->sn }} ({{ $dev->model }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label>Command</label>
                    <select id="cmdSelect" onchange="updateParamTemplate()">
                        <optgroup label="User Management">
                            <option value="setuserinfo">setuserinfo (Create/Update User & Credential)</option>
                            <option value="deleteuser">deleteuser (Delete User)</option>
                            <option value="getusername">getusername (Query User Name)</option>
                            <option value="getuserlist">getuserlist (Get User IDs Paginated)</option>
                            <option value="getuserinfo">getuserinfo (Detailed User Info)</option>
                            <option value="adduser">adduser (On-Device Enrollment Wizard)</option>
                            <option value="checkregstatus">checkregstatus (Enrollment Status)</option>
                            <option value="enableuser">enableuser (Enable/Disable User)</option>
                        </optgroup>
                        <optgroup label="Log Management">
                            <option value="getnewlog" selected>getnewlog (Fetch Unread Punch Logs)</option>
                            <option value="getalllog">getalllog (Fetch All Punch Logs)</option>
                            <option value="cleanlog">cleanlog (Clear Logs On Device)</option>
                        </optgroup>
                        <optgroup label="Device Management">
                            <option value="getdevinfo">getdevinfo (Get Device Parameters)</option>
                            <option value="getdevcap">getdevcap (Get Capacity Usage)</option>
                            <option value="gettime">gettime (Get Device Clock)</option>
                            <option value="settime">settime (Set Device Clock)</option>
                            <option value="reboot">reboot (Reboot Hardware)</option>
                            <option value="checklive">checklive (Send Heartbeat Ping)</option>
                        </optgroup>
                        <optgroup label="Access Control">
                            <option value="opendoor">opendoor (Remote Door Unlock)</option>
                            <option value="lockctrl">lockctrl (Lock Relay State)</option>
                            <option value="getdoorstatus">getdoorstatus (Door Sensor Status)</option>
                        </optgroup>
                        <optgroup label="Video Intercom">
                            <option value="enablewebrtc">enablewebrtc (Configure WebRTC)</option>
                            <option value="callcancel">callcancel (Cancel Call)</option>
                            <option value="talklock">talklock (Unlock During Call)</option>
                        </optgroup>
                    </select>
                </div>

                <div class="form-group">
                    <label>JSON Parameters</label>
                    <textarea id="paramJson">{}</textarea>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <button class="btn btn-blue" onclick="dispatchCommand()">Execute Command</button>
                    <span id="cmdStatus" style="font-size: 12px; color: var(--text-muted);"></span>
                </div>

                <label>Terminal Response Output</label>
                <div class="console-output" id="consoleOutput">// Awaiting command execution...</div>
            </div>
        </div>

        <!-- Recent Logs Table -->
        <div class="card">
            <div class="card-header">
                <h2>Real-time Ingested Attendance Punches</h2>
                <span style="font-size: 12px; color: var(--text-muted);">Auto-saved to Database</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Device SN</th>
                        <th>User ID</th>
                        <th>Name</th>
                        <th>Mode</th>
                        <th>Direction</th>
                        <th>Event</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentLogs as $log)
                        <tr>
                            <td>{{ $log->punch_time }}</td>
                            <td><span style="color: #38bdf8;">{{ $log->sn }}</span></td>
                            <td><strong>{{ $log->enrollid }}</strong></td>
                            <td>{{ $log->name ?: 'N/A' }}</td>
                            <td><span class="badge badge-online">{{ $log->mode_name }}</span></td>
                            <td>{{ $log->inout === 0 ? 'Check In' : 'Check Out' }}</td>
                            <td>{{ $log->event }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 20px;">
                                No attendance records captured yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const templates = {
            'getnewlog': {},
            'getalllog': { page: 1, pagesize: 50 },
            'cleanlog': {},
            'getdevinfo': {},
            'getdevcap': {},
            'gettime': {},
            'settime': { time: new Date().toISOString().replace('T', ' ').substring(0, 19) },
            'reboot': {},
            'checklive': {},
            'opendoor': { doornum: 1 },
            'lockctrl': { ctrl: 0, doornum: 1 },
            'getdoorstatus': { doornum: 1 },
            'getuserlist': { page: 1, pagesize: 50 },
            'getusername': { enrollid: 1 },
            'getuserinfo': { enrollid: 1, backupnum: 0 },
            'deleteuser': { enrollid: 1 },
            'enableuser': { enrollid: 1, enable: 1 },
            'setuserinfo': { enrollid: 1, name: 'John Doe', backupnum: 10, record: 123456, admin: 0 },
            'adduser': { enrollid: 1, backupnum: 0, admin: 0 },
            'checkregstatus': { enrollid: 1, backupnum: 0 },
            'enablewebrtc': { intercom: true, monitor: true, waitseconds: 30 }
        };

        function updateParamTemplate() {
            const cmd = document.getElementById('cmdSelect').value;
            const tpl = templates[cmd] || {};
            document.getElementById('paramJson').value = JSON.stringify(tpl, null, 2);
        }

        async function dispatchCommand() {
            const sn = document.getElementById('targetSn').value;
            const cmd = document.getElementById('cmdSelect').value;
            const statusEl = document.getElementById('cmdStatus');
            const outEl = document.getElementById('consoleOutput');

            if (!sn) {
                alert('Please select a target device.');
                return;
            }

            let params = {};
            try {
                params = JSON.parse(document.getElementById('paramJson').value || '{}');
            } catch (e) {
                alert('Invalid JSON in parameters: ' + e.message);
                return;
            }

            statusEl.textContent = 'Transmitting to device over WebSocket...';
            outEl.textContent = `// Sending cmd "${cmd}" to ${sn}...`;

            try {
                const res = await fetch(`/api/aiface/devices/${sn}/command`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cmd, params, timeout: 10 })
                });

                const data = await res.json();
                statusEl.textContent = data.result ? 'Success (200 OK)' : 'Failed';
                outEl.textContent = JSON.stringify(data, null, 2);
            } catch (err) {
                statusEl.textContent = 'Error';
                outEl.textContent = '// Network or server error: ' + err.message;
            }
        }

        async function quickAction(sn, action, params = {}) {
            try {
                const res = await fetch(`/api/aiface/devices/${sn}/${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(params)
                });
                const data = await res.json();
                alert(`Action [${action}] result:\n` + JSON.stringify(data, null, 2));
            } catch (e) {
                alert('Action failed: ' + e.message);
            }
        }
    </script>
</body>
</html>

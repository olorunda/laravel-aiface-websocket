# Laravel AiFace Biometric WebSocket Server & Device Command SDK

A high-performance, asynchronous pure-PHP RFC 6455 **WebSocket / WSS Server** and complete **Command Interaction SDK** for **TimyTeco AiFace Biometric Terminals** (Visible Light Facial Recognition, Fingerprint, Palm Vein, RFID Card, PIN, QR Code Access Control & Time Attendance).

---

## ⚡ Architecture Overview

In the TimyTeco AiFace BS communication protocol, the **physical biometric device acts as a client** that initiates an outbound persistent WebSocket connection to your Laravel server. This enables seamless **Wide Area Network (WAN)** and cloud deployment across network segments without requiring static public IPs or port forwarding on the device's local network.

```text
┌────────────────────────────────────────────────────────┐
│             AiFace Biometric Hardware                  │
│    (Face / Fingerprint / Palm / RFID / PIN / QR)       │
└───────────────────────────┬────────────────────────────┘
                            │ Outbound WebSocket (WS/WSS)
                            │ ws://<your-laravel-server>:7788/pub/chat
                            ▼
┌────────────────────────────────────────────────────────┐
│     Laravel AiFace WebSocket Server Engine Daemon      │
│               `php artisan aiface:serve`               │
│                                                        │
│  - RFC 6455 Framing & Ping/Pong Heartbeat (10s)        │
│  - Auto-Registration & Cloud Clock Sync (`cmd: reg`)   │
│  - Real-time Punch Ingestion (`cmd: sendlog`)          │
│  - On-Device Enrollment Ingestion (`cmd: senduser`)    │
│  - Bi-directional Command Dispatcher & Future Resolver │
└───────────┬───────────────────────────────┬────────────┘
            │                               │
            ▼                               ▼
┌────────────────────────┐      ┌────────────────────────┐
│   Database / Storage   │      │    Webhook Engine      │
│  Eloquent Models:      │      │  Async HTTP POST with  │
│  - aiface_devices      │      │  HMAC SHA-256 Signature│
│  - aiface_logs         │      │  To HR / External APIs │
│  - aiface_users        │      └────────────────────────┘
│  - aiface_credentials  │
└────────────────────────┘
            ▲
            │
┌───────────┴────────────────────────────────────────────┐
│         Developer Interfaces & Interaction API         │
│  - Laravel Facade: `AiFace::device('LF00000001')->...` │
│  - Artisan CLI: `php artisan aiface:command {sn} {cmd}`│
│  - HTTP REST API: `/api/aiface/devices/...`            │
│  - Web Management Dashboard: `/aiface/dashboard`       │
└────────────────────────────────────────────────────────┘
```

---

## 🚀 Quickstart Installation

### 1. Register Package in your Laravel Application

In your Laravel project `composer.json`, add this package:

```bash
composer require olorunda/laravel-aiface-websocket
```

*(If testing locally in the same monorepo/workspace, add as a path repository):*
```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-aiface-websocket"
    }
]
```

### 2. Publish Configuration & Run Database Migrations

```bash
php artisan vendor:publish --tag=aiface-config
php artisan migrate
```

### 3. Start the WebSocket Server Daemon

```bash
php artisan aiface:serve
```

Output:
```text
===============================================================
  AiFace Biometric WebSocket Server Daemon  
===============================================================
  ● Protocol:       ws
  ● Listening:      0.0.0.0:7788/pub/chat
  ● Device URL:     ws://<YOUR_SERVER_IP>:7788/pub/chat
  ● Storage:        Enabled
  ● Webhooks:       Disabled
===============================================================
```

To run on a custom port or with TLS/WSS:
```bash
php artisan aiface:serve --port=7788 --path=/pub/chat --wss
```

---

## 📱 Hardware Device Configuration

On your physical AiFace device touchscreen:
1. Tap **[Menu / Settings]** ➔ **Comm.** (Communication) ➔ **Server Settings / Cloud Settings**.
2. Set **Protocol Mode**: `WebSocket` (or `BS / Cloud Server`).
3. Set **Server IP / Domain**: Your server IP address (e.g. `192.168.1.100` or `cloud.yourdomain.com`).
4. Set **Server Port**: `7788` (or `433` for WSS).
5. Set **Server Path**: `/pub/chat` (default path).
6. Save and restart network. The device will immediately connect and send the `reg` registration handshake.

---

## 💻 Laravel Facade Usage

Interact with any connected device using the `AiFace` facade:

```php
use AiFace\WebSocket\Facades\AiFace;
use AiFace\WebSocket\Core\Protocol;

// Check if device is connected online
if (AiFace::isOnline('LF00000001')) {
    echo "Device is online!";
}

// 1. Remote Unlock Door
$response = AiFace::device('LF00000001')->openDoor(doorNum: 1);

// 2. Synchronize Device Clock with Server Time
$response = AiFace::device('LF00000001')->syncTime();

// 3. Reboot Device Hardware
$response = AiFace::device('LF00000001')->reboot();

// 4. Enroll User with Face Photo (base64 JPG)
$response = AiFace::device('LF00000001')->setUserFacePhoto(
    enrollId: 101,
    name: 'Sarah Connor',
    base64Jpg: base64_encode(file_get_contents('/path/to/face.jpg')),
    admin: 0
);

// 5. Enroll User with RFID Card Number
$response = AiFace::device('LF00000001')->setUserCard(
    enrollId: 102,
    name: 'John Doe',
    cardNumber: '9876543210'
);

// 6. Enroll User with PIN / Password
$response = AiFace::device('LF00000001')->setUserPassword(
    enrollId: 103,
    name: 'Admin User',
    pwd: 123456,
    admin: 1 // Device administrator
);

// 7. Pull Unread Attendance Records
$logs = AiFace::device('LF00000001')->getNewLog();

// 8. Trigger Device-Side Enrollment Wizard on hardware screen
AiFace::device('LF00000001')->addUser(
    enrollId: 105,
    backupNum: Protocol::BACKUP_FP_0 // Fingerprint slot 0
);
```

---

## 🚀 Realistic Real-World Workflow Example

Below is a complete, production-grade example illustrating how to manage employee lifecycles (enrolling and offboarding users) via a Controller and automatically track attendance punches using Event Listeners.

### 1. Employee Biometric Controller (Enrolling & Deleting Users)

Create a controller (`app/Http/Controllers/BiometricEmployeeController.php`):

```php
namespace App\Http\Controllers;

use AiFace\WebSocket\Facades\AiFace;
use AiFace\WebSocket\Core\Protocol;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricEmployeeController extends Controller
{
    protected string $deviceSn = 'LF00000001';

    /**
     * Step 1: Enroll an employee onto the biometric terminal
     */
    public function enroll(Request $request, int $userId): JsonResponse
    {
        $employee = User::findOrFail($userId);

        // 1. Verify the biometric hardware terminal is connected online
        if (!AiFace::isOnline($this->deviceSn)) {
            return response()->json([
                'success' => false,
                'message' => "Terminal [{$this->deviceSn}] is currently offline.",
            ], 503);
        }

        // 2. Check remaining device memory/capacity before adding
        $cap = AiFace::device($this->deviceSn)->getDevCap();
        if (($cap['useduser'] ?? 0) >= ($cap['usersize'] ?? 10000)) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal user capacity is full.',
            ], 422);
        }

        // 3. Enroll employee with card and/or PIN password
        // (backupnum: 10 = password, 11 = card, 50 = face photo)
        $enrollResult = AiFace::device($this->deviceSn)->setUserInfo(
            enrollId: $employee->id,
            name: $employee->name,
            backupNum: Protocol::BACKUP_PASSWORD,
            record: $request->input('pin', '123456'), // User PIN
            admin: $employee->is_admin ? 1 : 0,       // 1 = Terminal Administrator
            enable: 1,                                // 1 = Active, 0 = Suspended
            extra: [
                'card' => $request->input('card_number', '99887766'),
                'aliasid' => 'EMP-' . $employee->id,
            ]
        );

        // 4. (Optional) Enroll face photo if uploaded
        if ($request->hasFile('face_photo')) {
            $base64Image = base64_encode(file_get_contents($request->file('face_photo')->getRealPath()));
            AiFace::device($this->deviceSn)->setUserFacePhoto(
                enrollId: $employee->id,
                name: $employee->name,
                base64Jpg: $base64Image
            );
        }

        // 5. (Optional) Prompt the device screen to open enrollment wizard for fingerprint
        if ($request->boolean('scan_fingerprint')) {
            AiFace::device($this->deviceSn)->addUser(
                enrollId: $employee->id,
                backupNum: Protocol::BACKUP_FP_0 // Fingerprint slot 0
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Employee #{$employee->id} ({$employee->name}) enrolled successfully.",
            'terminal_response' => $enrollResult,
        ]);
    }

    /**
     * Step 2: Offboard / Delete an employee from the biometric terminal
     */
    public function offboard(int $userId): JsonResponse
    {
        $employee = User::findOrFail($userId);

        if (!AiFace::isOnline($this->deviceSn)) {
            return response()->json([
                'success' => false,
                'message' => "Terminal [{$this->deviceSn}] is offline. Reconnect terminal to delete.",
            ], 503);
        }

        // Delete user and all associated biometric credentials (face, fingerprints, card, PIN)
        $deleteResult = AiFace::device($this->deviceSn)->deleteUser(
            enrollId: $employee->id
        );

        return response()->json([
            'success' => true,
            'message' => "Employee #{$employee->id} deleted from terminal.",
            'terminal_response' => $deleteResult,
        ]);
    }

    /**
     * Step 3: Revoke only a specific credential (e.g. lost RFID card) without deleting user
     */
    public function revokeCard(int $userId): JsonResponse
    {
        // Deleting with backupNum = 11 removes only the RFID card credential
        $result = AiFace::device($this->deviceSn)->deleteUser(
            enrollId: $userId,
            backupNum: Protocol::BACKUP_CARD
        );

        return response()->json([
            'success' => true,
            'message' => "RFID card revoked for user #{$userId}.",
            'terminal_response' => $result,
        ]);
    }

    /**
     * Step 4: Delayed / Scheduled Deletion (Auto-Expire Access)
     * Useful for hotel guests, temporary visitors, contract workers, or scheduled offboarding.
     */
    public function scheduleOffboarding(int $userId): JsonResponse
    {
        // Option A: Pass delay in seconds (e.g., 3600 = 1 hour)
        $result = AiFace::device($this->deviceSn)->deleteUser(
            enrollId: $userId,
            delay: 3600
        );

        // Option B: Pass a Carbon / DateTime instance
        // $result = AiFace::device($this->deviceSn)->delayedDeleteUser(
        //     enrollId: $userId,
        //     delay: now()->addHours(8)
        // );

        return response()->json([
            'success' => true,
            'message' => "User #{$userId} scheduled for automatic removal when time elapses.",
            'task'    => $result,
        ]);
    }

    /**
     * Step 5: Cancel a scheduled deletion before it elapses
     */
    public function cancelScheduledOffboarding(int $userId): JsonResponse
    {
        $result = AiFace::device($this->deviceSn)->cancelDelayedDelete($userId);

        return response()->json([
            'success' => true,
            'result'  => $result,
        ]);
    }
}
```

---

### 2. Real-Time Attendance Event Listener

Create an event listener (`app/Listeners/HandleBiometricAttendance.php`) to automatically process punches when employees clock in or clock out:

```php
namespace App\Listeners;

use AiFace\WebSocket\Events\UserClockedIn;
use AiFace\WebSocket\Events\UserClockedOut;
use App\Models\Timesheet;
use Illuminate\Support\Facades\Log;

class HandleBiometricAttendance
{
    /**
     * Handle Clock-In (Check-In) Events
     */
    public function handleClockIn(UserClockedIn $event): void
    {
        Log::info("Employee #{$event->enrollId} ({$event->name}) clocked IN on terminal {$event->sn} at {$event->time} via {$event->modeDesc}");

        // Record attendance in your database
        Timesheet::create([
            'user_id'         => $event->enrollId,
            'employee_name'   => $event->name,
            'device_sn'       => $event->sn,
            'action'          => 'clock_in',
            'punch_time'      => $event->time,
            'verification'    => $event->modeDesc, // e.g. "Face Recognition", "Fingerprint", "Card", "Password"
            'snapshot_base64' => $event->image,    // Captured snapshot if terminal camera is configured
        ]);

        // Trigger notifications, Slack alerts, or dispatch payroll sync jobs
    }

    /**
     * Handle Clock-Out (Check-Out) Events
     */
    public function handleClockOut(UserClockedOut $event): void
    {
        Log::info("Employee #{$event->enrollId} ({$event->name}) clocked OUT on terminal {$event->sn} at {$event->time}");

        Timesheet::create([
            'user_id'         => $event->enrollId,
            'employee_name'   => $event->name,
            'device_sn'       => $event->sn,
            'action'          => 'clock_out',
            'punch_time'      => $event->time,
            'verification'    => $event->modeDesc,
        ]);
    }
}
```

---

### 3. Registering the Listener in Laravel

In your `app/Providers/AppServiceProvider.php` (Laravel 11 & 12) or `EventServiceProvider.php` (Laravel 9 & 10):

```php
namespace App\Providers;

use AiFace\WebSocket\Events\UserClockedIn;
use AiFace\WebSocket\Events\UserClockedOut;
use App\Listeners\HandleBiometricAttendance;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(UserClockedIn::class, [HandleBiometricAttendance::class, 'handleClockIn']);
        Event::listen(UserClockedOut::class, [HandleBiometricAttendance::class, 'handleClockOut']);
    }
}
```

---

## 🛠️ Complete Command Reference (All 80+ Commands)

Every command defined in the TimyTeco AiFace specification is implemented with typed methods:

### 1. User Management (17 Commands)
| Method | Protocol Command | Description |
|---|---|---|
| `setUserInfo($enrollId, $name, ...)` | `setuserinfo` | Set user profile, card, password, face, fingerprint |
| `setUserFacePhoto($id, $name, $jpg)` | `setuserinfo` (50) | Quick enroll face image (base64 JPG) |
| `setUserPassword($id, $name, $pwd)` | `setuserinfo` (10) | Quick enroll PIN/password |
| `setUserCard($id, $name, $card)` | `setuserinfo` (11) | Quick enroll RFID card number |
| `setUserFingerprint($id, $name, $slot, $hex)` | `setuserinfo` (0-9)| Quick enroll fingerprint with 0-compression |
| `deleteUser($enrollId, $backupNum = null, $delay = null)` | `deleteuser` | Delete user immediately or schedule delayed deletion (`int` seconds or `DateTime`) |
| `delayedDeleteUser($enrollId, $delay, $backupNum = null)` | `delayeddelete` | Schedule user/credential deletion after delay elapses (offline resilient) |
| `cancelDelayedDelete($enrollId)` | `canceldelayeddelete` | Cancel pending delayed deletion for user |
| `getPendingDelayedDeletes()` | `getpendingdelayeddeletes` | Retrieve list of all scheduled delayed delete tasks |
| `cleanUser()` | `cleanuser` | Clear all users from hardware |
| `setUserName(array $users)` | `setusername` | Batch set user names |
| `getUserName($enrollId)` | `getusername` | Query user name by ID |
| `getUserList($page, $pageSize)` | `getuserlist` | Paginated list of user IDs |
| `getUserIds()` | `getuserids` | Query all enrolled user IDs |
| `getUnusedUserId()` | `getunuserdid` | Get next available user ID |
| `checkUserId($enrollId)` | `checkuserid` | Check if user ID exists |
| `getUserInfo($enrollId, $backupNum)` | `getuserinfo` | Retrieve credential data |
| `getAllUsers($page, $pageSize)` | `getallusers` | Batch retrieve user data |
| `addUser($enrollId, $backupNum, $admin)` | `adduser` | Start on-device enrollment wizard |
| `checkRegStatus($enrollId, $backupNum)`| `checkregstatus` | Check status of enrollment |
| `cancelAddUser($enrollId, $backupNum)` | `canceladduser` | Cancel on-device enrollment |
| `enableUser($enrollId, $enable)` | `enableuser` | Enable/disable user account |
| `getUserProfile($enrollId)` | `getuserprofile` | Get custom profile JSON |
| `setUserProfile($enrollId, $profile)` | `setuserprofile` | Set custom profile JSON |

### 2. Log Management (4 Commands)
| Method | Protocol Command | Description |
|---|---|---|
| `getNewLog()` | `getnewlog` | Fetch unread attendance punch records |
| `getAllLog($page, $pageSize, $start, $end)` | `getalllog` | Paginated search of attendance records |
| `cleanLog()` | `cleanlog` | Clear all logs from hardware |
| `cleanLogPhoto()` | `cleanlogphoto` | Clear snapshot photos from hardware |

### 3. Device Management (15 Commands)
| Method | Protocol Command | Description |
|---|---|---|
| `getDevInfo()` | `getdevinfo` | Query hardware parameters |
| `setDevInfo(array $devInfo)` | `setdevinfo` | Configure volume, timeout, etc. |
| `getDevCap()` | `getdevcap` | Capacity and usage statistics |
| `getTime()` | `gettime` | Get device RTC clock |
| `setTime($time)` | `settime` | Set device clock |
| `syncTime()` | `settime` | Synchronize with current server time |
| `initsys()` | `initsys` | Factory reset system |
| `initMenu()` | `initmenu` | Reset menu configurations |
| `cleanDatabase()` | `cleandatebase` | Wipe database |
| `cleanAdmin()` | `cleanadmin` | Demote all administrators to normal users |
| `cleanInactiveUser($days)` | `cleaninactiveuser` | Delete users inactive for N days |
| `reboot()` | `reboot` | Reboot device hardware |
| `disableDevice()` | `disabledevice` | Disable recognition |
| `enableDevice()` | `enabledevice` | Enable recognition |
| `checkLive()` | `checklive` | Heartbeat ping from server |
| `getReg()` | `getreg` | Read registration info |

### 4. Access Control (9 Commands)
| Method | Protocol Command | Description |
|---|---|---|
| `openDoor($doorNum)` | `opendoor` | Remote unlock door relay |
| `lockCtrl($ctrl, $doorNum)` | `lockctrl` | 0=Normal, 1=Always Open, 2=Always Closed |
| `getDoorStatus($doorNum)` | `getdoorstatus` | Query magnetic door sensor state |
| `setDevLock(array $lockParams)` | `setdevlock` | Set relay delay, sensor mode, alarm |
| `getDevLock()` | `getdevlock` | Read access lock parameters |
| `getUserLock($enrollId)` | `getuserlock` | Get user timezone / door group |
| `setUserLock(array $records)` | `setuserlock` | Batch set user access permissions |
| `deleteUserLock($enrollId)` | `deleteuserlock` | Remove user access permissions |
| `cleanUserLock()` | `cleanuserlock` | Clear all user permissions |

### 5. Attendance Management (6 Commands)
| Method | Protocol Command | Description |
|---|---|---|
| `getShift($shiftId)` | `getshift` | Query shift schedule |
| `setShift($shiftId, array $records)` | `setshift` | Set shift schedule |
| `getBellTime()` | `getbelltime` | Query bell alarm schedule |
| `setBellTime(array $bells)` | `setbelltime` | Set bell alarm schedule |
| `setHoliday(array $holidays)` | `setholiday` | Set holiday dates |
| `getHoliday()` | `getholiday` | Query holiday dates |

### 6. File Management (3 Commands)
| Method | Protocol Command | Description |
|---|---|---|
| `getDir($path)` | `getdir` | List files in device directory |
| `getFile($filename, $index, $count)` | `getfile` | Download file from device (base64) |
| `writeFile($filename, $base64, ...)` | `writefile` | Upload file to device (base64) |

### 7. Other System Commands (15 Commands)
| Method | Protocol Command | Description |
|---|---|---|
| `upgrade($url, $md5)` | `upgrade` | Firmware upgrade from server URL |
| `forceOta()` | `forceota` | Force immediate OTA check |
| `setOtaServer($url)` | `setotaserver` | Configure OTA update endpoint |
| `getOtaServer()` | `getotaserver` | Query OTA update endpoint |
| `setScreensaver($base64Data)` | `setscreensaver` | Set screensaver image/video |
| `setVoice($index, $base64Audio)` | `setvoice` | Set custom voice audio prompts |
| `keypad($show)` | `keypad` | Control on-screen keypad |
| `verify($enrollId, $mode)` | `verify` | Remote biometric verification |
| `setDepartment(array $departments)` | `setdepartment` | Configure department list |
| `getDepartment()` | `getdepartment` | Query department list |
| `setCompanyName($name)` | `setcompanyname` | Set company title on screen |
| `getCompanyName()` | `getcompanyname` | Query company title |
| `setQuestionnaire(array $questions)`| `setquestionnaire` | Configure survey questions |
| `getQuestionnaire()` | `getquestionnaire` | Query questionnaires |
| `cleanQuestionnaire()` | `cleanquestionnaire`| Clear questionnaires |

### 8. Video Intercom Commands (11 Commands)
| Method | Protocol Command | Description |
|---|---|---|
| `enableWebRtc(...)` | `enablewebrtc` | Configure WebRTC, STUN, and TURN |
| `callAccept($sessionId, $accept)` | `callaccept` | Accept or reject video call |
| `viewReady($sessionId)` | `viewready` | Signal ready for stream |
| `answer($sessionId, $sdp)` | `answer` | Send WebRTC answer SDP |
| `iceCandidate($sessionId, ...)` | `icecandidate` | Send ICE candidate |
| `callCancel($sessionId)` | `callcancel` | Terminate video call |
| `talkLock($sessionId, $doorNum)` | `talklock` | Unlock door during active call |
| `monitorCall($sessionId)` | `monitorcall` | Start video monitoring |
| `setViSelection(array $selections)` | `setviselection` | Set visual selection menu |

---

## 🌐 HTTP REST API & Webhooks

### REST Endpoints
- `GET /api/aiface/devices`: List all online and registered hardware.
- `GET /api/aiface/devices/{sn}`: Detailed parameters of a specific device.
- `POST /api/aiface/devices/{sn}/command`: Send arbitrary JSON command to device.
- `POST /api/aiface/devices/{sn}/opendoor`: Quick remote door unlock.
- `POST /api/aiface/devices/{sn}/reboot`: Hardware reboot.
- `POST /api/aiface/devices/{sn}/users`: Create / update user on terminal.
- `DELETE /api/aiface/devices/{sn}/users/{enrollid}`: Delete user immediately or schedule delayed deletion (`?delay=3600`).
- `GET /api/aiface/devices/{sn}/delayed-deletes`: List all scheduled delayed deletions for device.
- `DELETE /api/aiface/devices/{sn}/delayed-deletes/{enrollid}`: Cancel a scheduled delayed deletion.
- `GET /api/aiface/devices/{sn}/new-logs`: Fetch unread logs.
- `GET /api/aiface/logs`: View stored attendance punches.
- `GET /api/aiface/commands/catalog`: Complete command schema catalog.

Import `postman_collection.json` into Postman for ready-to-use requests.

### Webhook & Laravel Event Listeners

The package routes all hardware events through Laravel's standard Event Dispatcher and a dedicated event listener (`DispatchAiFaceWebhook`):

#### 1. Listening to Clock-In & Biometric Events in Laravel
You can attach listeners or subscribers in your own application:

```php
use AiFace\WebSocket\Events\UserClockedIn;
use AiFace\WebSocket\Events\UserClockedOut;
use AiFace\WebSocket\Events\UserDeleteScheduled;
use AiFace\WebSocket\Events\UserDeleted;
use AiFace\WebSocket\Events\AttendanceLogReceived;
use AiFace\WebSocket\Events\DeviceRegistered;
use Illuminate\Support\Facades\Event;

// Triggered whenever a user clocks in (inout = 0)
Event::listen(UserClockedIn::class, function (UserClockedIn $event) {
    logger()->info("User #{$event->enrollId} ({$event->name}) clocked IN on {$event->sn} at {$event->time} via {$event->modeDesc}");
    // Example: send SMS, notify Slack, update attendance timesheet
});

// Triggered whenever a user clocks out (inout = 1)
Event::listen(UserClockedOut::class, function (UserClockedOut $event) {
    logger()->info("User #{$event->enrollId} ({$event->name}) clocked OUT on {$event->sn} at {$event->time}");
});

// Triggered when a delayed deletion task is scheduled
Event::listen(UserDeleteScheduled::class, function (UserDeleteScheduled $event) {
    logger()->info("User #{$event->enrollId} scheduled for deletion on {$event->sn} in {$event->delaySeconds}s (at " . date('Y-m-d H:i:s', $event->executeAt) . ")");
});

// Triggered when deletion is transmitted and effected on device
Event::listen(UserDeleted::class, function (UserDeleted $event) {
    logger()->info("User #{$event->enrollId} deleted on {$event->sn}");
});

// Triggered when a device registers or completes handshake
Event::listen(DeviceRegistered::class, function (DeviceRegistered $event) {
    logger()->info("AiFace device {$event->sn} registered from IP: {$event->ip}");
});
```

#### 2. External Webhook Event Forwarding
Configure external HTTP webhook endpoints in `config/aiface.php`:
```php
'webhooks' => [
    'enabled' => true,
    'url' => 'https://your-domain.com/webhooks/aiface',
    'secret' => 'your-secret-key',
    'events' => [
        'attendance.clockin',       // Individual user clock-in punch
        'attendance.clockout',      // Individual user clock-out punch
        'attendance.logged',        // Batch attendance log payload
        'device.registered',        // Device handshake completed
        'device.connected',         // New socket connected
        'device.disconnected',      // Device disconnected
        'user.pushed',              // On-device user enrollment report
        'user.delete_scheduled',    // User deletion task scheduled
        'user.deleted',             // User deletion effected on device
        'pin.received',             // Door access PIN entered
        'qrcode.scanned',           // Visitor/employee QR code scan
        'gps.received',             // Device GPS location
        'intercom.call',            // Video intercom doorbell ring
    ],
],
```

Incoming webhook requests include HMAC SHA-256 signatures for tamper verification:
```http
POST /webhooks/aiface HTTP/1.1
Host: your-domain.com
Content-Type: application/json
X-AiFace-Event: attendance.clockin
X-AiFace-Timestamp: 1773057600
X-AiFace-Signature: sha256=abcdef1234567890abcdef1234567890...

{
  "event": "attendance.clockin",
  "timestamp": 1773057600,
  "data": {
    "sn": "LF00000001",
    "enrollid": 101,
    "name": "Alice Johnson",
    "time": "2026-09-07 08:30:00",
    "action": "clock_in",
    "inout": 0,
    "mode": 3,
    "mode_desc": "Face Recognition",
    "aliasid": "EMP101",
    "image": null
  }
}
```

---

## 🧪 Testing with Mock Device Simulator

Run the included pure-PHP mock terminal simulator to test end-to-end communication without physical hardware:

```bash
# Simulates physical hardware with an embedded SQLite database (tynyteko_LF00000001.sqlite):
php examples/mock_device.php 127.0.0.1 7788 /pub/chat LF00000001
```

The mock device maintains its own **local SQLite database** (`tynyteko_{SN}.sqlite`) simulating real hardware storage:
- **Persistent Biometric Storage**: Real CRUD for users, credentials (passwords, RFID cards, facial feature templates, fingerprints).
- **Dynamic Command Handling**: Responds to `setuserinfo`, `deleteuser`, `cleanuser`, `getusername`, `getuserlist`, `getuserids`, `getunuserdid`, `checkuserid`, `getuserinfo`, `getallusers`, `getdevcap`, `getdevinfo`, `settime`, etc.
- **Realistic Attendance Simulation**: Generates facial scan punch logs (`sendlog`) for enrolled database users and processes server confirmations (`mark: true`).

Run the test suite:
```bash
vendor/bin/phpunit
```

---

## 📜 License
MIT License.

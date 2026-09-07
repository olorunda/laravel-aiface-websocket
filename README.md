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
| `deleteUser($enrollId, $backupNum = null)` | `deleteuser` | Delete user or specific credential |
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
- `POST /api/aiface/devices/{sn}/sync-time`: Synchronize device clock.
- `GET /api/aiface/devices/{sn}/new-logs`: Fetch unread logs.
- `GET /api/aiface/logs`: View stored attendance punches.
- `GET /api/aiface/commands/catalog`: Complete command schema catalog.

Import `postman_collection.json` into Postman for ready-to-use requests.

### Webhook Event Forwarding
Configure `config/aiface.php`:
```php
'webhooks' => [
    'enabled' => true,
    'url' => 'https://your-domain.com/webhooks/aiface',
    'secret' => 'your-secret-key',
],
```

Incoming webhook requests include HMAC SHA-256 signatures:
```text
X-AiFace-Event: attendance.logged
X-AiFace-Timestamp: 1773057600
X-AiFace-Signature: sha256=abcdef123456...
```

---

## 🧪 Testing with Mock Device Simulator

Run the included pure-PHP mock terminal simulator to test end-to-end communication without physical hardware:

```bash
php examples/mock_device.php 127.0.0.1 7788 /pub/chat LF00000001
```

Run test suite:
```bash
vendor/bin/phpunit
```

---

## 📜 License
MIT License.

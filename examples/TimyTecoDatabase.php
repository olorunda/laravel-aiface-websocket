<?php

namespace AiFace\WebSocket\Mock;

use PDO;

/**
 * TimyTeco Device SQLite Database Simulator.
 * 
 * Manages device configuration, user credentials, and attendance logs
 * inside an SQLite database to accurately simulate on-device storage.
 */
class TimyTecoDatabase
{
    private PDO $pdo;
    private string $sn;
    private string $dbPath;

    public function __construct(string $dbPath, string $sn = 'LF00000001')
    {
        $this->dbPath = $dbPath;
        $this->sn = $sn;

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $this->pdo = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $this->pdo->exec("PRAGMA journal_mode = WAL;");
        $this->pdo->exec("PRAGMA busy_timeout = 5000;");
        $this->pdo->exec("PRAGMA synchronous = NORMAL;");

        $this->migrate();
        $this->seedIfEmpty();
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Create SQLite schema.
     */
    private function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS device_config (
                sn TEXT PRIMARY KEY,
                modelname TEXT DEFAULT 'AiFace Pro 7',
                manufacturer TEXT DEFAULT 'TimyTeco',
                firmware TEXT DEFAULT '1.2.0',
                usersize INTEGER DEFAULT 10000,
                facesize INTEGER DEFAULT 5000,
                fpsize INTEGER DEFAULT 10000,
                logsize INTEGER DEFAULT 200000,
                voice INTEGER DEFAULT 7,
                language INTEGER DEFAULT 0,
                screensaver INTEGER DEFAULT 60,
                relay INTEGER DEFAULT 3,
                time TEXT DEFAULT NULL,
                cloudtime TEXT DEFAULT NULL,
                settings TEXT DEFAULT NULL
            );

            CREATE TABLE IF NOT EXISTS users (
                enrollid INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                admin INTEGER DEFAULT 0,
                card TEXT DEFAULT NULL,
                pwd TEXT DEFAULT NULL,
                enable INTEGER DEFAULT 1,
                aliasid TEXT DEFAULT NULL,
                profile TEXT DEFAULT NULL,
                created_at TEXT,
                updated_at TEXT
            );

            CREATE TABLE IF NOT EXISTS user_credentials (
                enrollid INTEGER NOT NULL,
                backupnum INTEGER NOT NULL,
                record TEXT DEFAULT NULL,
                updated_at TEXT,
                PRIMARY KEY (enrollid, backupnum),
                FOREIGN KEY (enrollid) REFERENCES users(enrollid) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS attendance_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                logindex INTEGER NOT NULL,
                enrollid INTEGER NOT NULL,
                name TEXT DEFAULT NULL,
                time TEXT NOT NULL,
                mode INTEGER DEFAULT 3,
                inout INTEGER DEFAULT 0,
                event INTEGER DEFAULT 0,
                aliasid TEXT DEFAULT NULL,
                note TEXT DEFAULT NULL,
                marked INTEGER DEFAULT 0,
                image TEXT DEFAULT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_logs_marked ON attendance_logs(marked);
            CREATE INDEX IF NOT EXISTS idx_logs_logindex ON attendance_logs(logindex);
        ");
    }

    /**
     * Seed realistic sample data if newly created.
     */
    public function seedIfEmpty(): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM device_config WHERE sn = ?");
        $stmt->execute([$this->sn]);
        if ((int) $stmt->fetchColumn() === 0) {
            $insert = $this->pdo->prepare("
                INSERT INTO device_config (sn, modelname, manufacturer, firmware, usersize, facesize, fpsize, logsize, voice, language, relay)
                VALUES (?, 'AiFace Pro 7', 'TimyTeco', '1.2.0', 10000, 5000, 10000, 200000, 7, 0, 3)
            ");
            $insert->execute([$this->sn]);
        }

        $userCount = (int) $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount === 0) {
            $now = date('Y-m-d H:i:s');
            $sampleUsers = [
                ['enrollid' => 101, 'name' => 'Alice Johnson', 'admin' => 1, 'card' => '88990011', 'pwd' => '123456', 'aliasid' => 'EMP101'],
                ['enrollid' => 102, 'name' => 'Bob Smith', 'admin' => 0, 'card' => '88990022', 'pwd' => '112233', 'aliasid' => 'EMP102'],
                ['enrollid' => 103, 'name' => 'Charlie Brown', 'admin' => 0, 'card' => '88990033', 'pwd' => '', 'aliasid' => 'EMP103'],
                ['enrollid' => 104, 'name' => 'David Wilson', 'admin' => 0, 'card' => '88990044', 'pwd' => '', 'aliasid' => 'EMP104'],
                ['enrollid' => 105, 'name' => 'Eve Adams', 'admin' => 0, 'card' => '88990055', 'pwd' => '998877', 'aliasid' => 'EMP105'],
            ];

            $userStmt = $this->pdo->prepare("
                INSERT INTO users (enrollid, name, admin, card, pwd, enable, aliasid, created_at, updated_at)
                VALUES (:enrollid, :name, :admin, :card, :pwd, 1, :aliasid, :now, :now)
            ");
            $credStmt = $this->pdo->prepare("
                INSERT INTO user_credentials (enrollid, backupnum, record, updated_at)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($sampleUsers as $u) {
                $userStmt->execute([
                    ':enrollid' => $u['enrollid'],
                    ':name' => $u['name'],
                    ':admin' => $u['admin'],
                    ':card' => $u['card'],
                    ':pwd' => $u['pwd'],
                    ':aliasid' => $u['aliasid'],
                    ':now' => $now,
                ]);

                // Face credential (backupnum = 12)
                $credStmt->execute([$u['enrollid'], 12, bin2hex("MOCK_FACE_FEATURE_VECTOR_DATA_USER_{$u['enrollid']}"), $now]);
                // Fingerprint 0 (backupnum = 0)
                $credStmt->execute([$u['enrollid'], 0, bin2hex("MOCK_FINGERPRINT_TEMPLATE_DATA_USER_{$u['enrollid']}"), $now]);
            }

            // Seed initial attendance log
            $this->insertLog(
                enrollId: 101,
                name: 'Alice Johnson',
                mode: 3,
                inout: 0,
                time: date('Y-m-d H:i:s', strtotime('-1 hour')),
                aliasid: 'EMP101',
                note: 'Initial Seed Punch'
            );
        }
    }

    /**
     * Reset device database to factory defaults.
     */
    public function resetFactory(): void
    {
        $this->pdo->exec("DELETE FROM attendance_logs; DELETE FROM user_credentials; DELETE FROM users; DELETE FROM device_config;");
        $this->seedIfEmpty();
    }

    // =========================================================================
    // DEVICE CONFIG & CAPACITY
    // =========================================================================

    public function getDeviceInfo(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM device_config WHERE sn = ?");
        $stmt->execute([$this->sn]);
        $row = $stmt->fetch();

        if (!$row) {
            return [
                'modelname' => 'AiFace Pro 7',
                'manufacturer' => 'TimyTeco',
                'firmware' => '1.2.0',
                'usersize' => 10000,
                'facesize' => 5000,
                'fpsize' => 10000,
                'logsize' => 200000,
                'time' => date('Y-m-d H:i:s'),
            ];
        }

        $row['time'] = $row['time'] ?? date('Y-m-d H:i:s');
        return $row;
    }

    public function updateCloudTime(string $cloudtime): void
    {
        $stmt = $this->pdo->prepare("UPDATE device_config SET cloudtime = ? WHERE sn = ?");
        $stmt->execute([$cloudtime, $this->sn]);
    }

    public function setDeviceTime(string $time): void
    {
        $stmt = $this->pdo->prepare("UPDATE device_config SET time = ? WHERE sn = ?");
        $stmt->execute([$time, $this->sn]);
    }

    public function getDeviceCap(): array
    {
        $info = $this->getDeviceInfo();

        $usedUser = (int) $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $usedFace = (int) $this->pdo->query("SELECT COUNT(*) FROM user_credentials WHERE backupnum = 12")->fetchColumn();
        $usedFp = (int) $this->pdo->query("SELECT COUNT(*) FROM user_credentials WHERE backupnum BETWEEN 0 AND 9")->fetchColumn();
        $usedLog = (int) $this->pdo->query("SELECT COUNT(*) FROM attendance_logs")->fetchColumn();

        return [
            'usersize' => (int) ($info['usersize'] ?? 10000),
            'facesize' => (int) ($info['facesize'] ?? 5000),
            'fpsize'   => (int) ($info['fpsize'] ?? 10000),
            'logsize'  => (int) ($info['logsize'] ?? 200000),
            'useduser' => $usedUser,
            'usedface' => $usedFace,
            'usedfp'   => $usedFp,
            'usedlog'  => $usedLog,
        ];
    }

    // =========================================================================
    // USER MANAGEMENT
    // =========================================================================

    /**
     * Upsert user and optional credential.
     */
    public function setUserInfo(
        int $enrollId,
        string $name,
        int $backupNum = 0,
        mixed $record = '',
        int $admin = 0,
        int $enable = 1,
        ?string $card = null,
        ?string $pwd = null,
        ?string $aliasid = null
    ): bool {
        $now = date('Y-m-d H:i:s');

        // Check if user already exists
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE enrollid = ?");
        $stmt->execute([$enrollId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update user
            $cardVal = $card ?? ($backupNum === 11 && !empty($record) ? (string)$record : $existing['card']);
            $pwdVal  = $pwd  ?? ($backupNum === 10 && !empty($record) ? (string)$record : $existing['pwd']);

            $update = $this->pdo->prepare("
                UPDATE users 
                SET name = ?, admin = ?, card = ?, pwd = ?, enable = ?, aliasid = COALESCE(?, aliasid), updated_at = ?
                WHERE enrollid = ?
            ");
            $update->execute([$name, $admin, $cardVal, $pwdVal, $enable, $aliasid, $now, $enrollId]);
        } else {
            // Insert user
            $cardVal = $card ?? ($backupNum === 11 && !empty($record) ? (string)$record : null);
            $pwdVal  = $pwd  ?? ($backupNum === 10 && !empty($record) ? (string)$record : null);

            $insert = $this->pdo->prepare("
                INSERT INTO users (enrollid, name, admin, card, pwd, enable, aliasid, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([$enrollId, $name, $admin, $cardVal, $pwdVal, $enable, $aliasid, $now, $now]);
        }

        // Handle credential record if provided
        if (!empty($record) || $record === '0') {
            $recordStr = is_array($record) ? json_encode($record) : (string) $record;
            $credStmt = $this->pdo->prepare("
                INSERT INTO user_credentials (enrollid, backupnum, record, updated_at)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(enrollid, backupnum) DO UPDATE SET record = excluded.record, updated_at = excluded.updated_at
            ");
            $credStmt->execute([$enrollId, $backupNum, $recordStr, $now]);
        }

        return true;
    }

    /**
     * Delete user or user credential.
     */
    public function deleteUser(int $enrollId, ?int $backupNum = null): bool
    {
        if ($backupNum !== null) {
            // Delete specific credential
            $stmt = $this->pdo->prepare("DELETE FROM user_credentials WHERE enrollid = ? AND backupnum = ?");
            $stmt->execute([$enrollId, $backupNum]);
            return true;
        }

        // Delete entire user and all credentials
        $this->pdo->prepare("DELETE FROM user_credentials WHERE enrollid = ?")->execute([$enrollId]);
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE enrollid = ?");
        $stmt->execute([$enrollId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Clear all users and credentials from the device.
     */
    public function cleanUser(): bool
    {
        $this->pdo->exec("DELETE FROM user_credentials; DELETE FROM users;");
        return true;
    }

    /**
     * Batch update user names.
     */
    public function setUserName(array $users): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            INSERT INTO users (enrollid, name, updated_at) 
            VALUES (:enrollid, :name, :now)
            ON CONFLICT(enrollid) DO UPDATE SET name = excluded.name, updated_at = excluded.updated_at
        ");

        $count = 0;
        foreach ($users as $u) {
            if (isset($u['enrollid'], $u['name'])) {
                $stmt->execute([':enrollid' => (int) $u['enrollid'], ':name' => $u['name'], ':now' => $now]);
                $count++;
            }
        }
        return $count;
    }

    public function getUserName(int $enrollId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT name FROM users WHERE enrollid = ?");
        $stmt->execute([$enrollId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }

    public function getUserIds(): array
    {
        return $this->pdo->query("SELECT enrollid FROM users ORDER BY enrollid ASC")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getUserList(int $page = 1, int $pageSize = 50): array
    {
        $offset = max(0, ($page - 1) * $pageSize);
        $stmt = $this->pdo->prepare("SELECT enrollid FROM users ORDER BY enrollid ASC LIMIT ? OFFSET ?");
        $stmt->execute([$pageSize, $offset]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getUnusedUserId(): int
    {
        $ids = $this->getUserIds();
        if (empty($ids)) {
            return 1;
        }

        return max($ids) + 1;
    }

    public function checkUserId(int $enrollId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE enrollid = ?");
        $stmt->execute([$enrollId]);
        return (bool) $stmt->fetchColumn();
    }

    public function getUserInfo(int $enrollId, int $backupNum = 0): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE enrollid = ?");
        $stmt->execute([$enrollId]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }

        $credStmt = $this->pdo->prepare("SELECT record FROM user_credentials WHERE enrollid = ? AND backupnum = ?");
        $credStmt->execute([$enrollId, $backupNum]);
        $record = $credStmt->fetchColumn();

        return [
            'enrollid'  => (int) $user['enrollid'],
            'name'      => $user['name'],
            'backupnum' => $backupNum,
            'admin'     => (int) $user['admin'],
            'record'    => $record !== false ? $record : '',
            'enable'    => (int) $user['enable'],
            'aliasid'   => $user['aliasid'] ?? '',
        ];
    }

    public function getAllUsers(int $page = 1, int $pageSize = 10, ?int $backupNum = null): array
    {
        $offset = max(0, ($page - 1) * $pageSize);
        $stmt = $this->pdo->prepare("SELECT * FROM users ORDER BY enrollid ASC LIMIT ? OFFSET ?");
        $stmt->execute([$pageSize, $offset]);
        $users = $stmt->fetchAll();

        $records = [];
        $credStmt = $this->pdo->prepare("SELECT record, backupnum FROM user_credentials WHERE enrollid = ?");

        foreach ($users as $u) {
            $rec = [
                'enrollid' => (int) $u['enrollid'],
                'name'     => $u['name'],
                'admin'    => (int) $u['admin'],
                'enable'   => (int) $u['enable'],
                'aliasid'  => $u['aliasid'] ?? '',
            ];

            if ($backupNum !== null) {
                $cStmt = $this->pdo->prepare("SELECT record FROM user_credentials WHERE enrollid = ? AND backupnum = ?");
                $cStmt->execute([$u['enrollid'], $backupNum]);
                $rec['backupnum'] = $backupNum;
                $rec['record'] = $cStmt->fetchColumn() ?: '';
            }

            $records[] = $rec;
        }

        return $records;
    }

    public function enableUser(int $enrollId, bool $enable): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET enable = ? WHERE enrollid = ?");
        $stmt->execute([$enable ? 1 : 0, $enrollId]);
        return $stmt->rowCount() > 0;
    }

    public function getUserProfile(int $enrollId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT profile FROM users WHERE enrollid = ?");
        $stmt->execute([$enrollId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }

    public function setUserProfile(int $enrollId, string $profile): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET profile = ? WHERE enrollid = ?");
        $stmt->execute([$profile, $enrollId]);
        return $stmt->rowCount() > 0;
    }

    public function getRandomEnrolledUser(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM users WHERE enable = 1 ORDER BY RANDOM() LIMIT 1");
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // =========================================================================
    // ATTENDANCE LOGS
    // =========================================================================

    public function insertLog(
        int $enrollId,
        ?string $name = null,
        int $mode = 3,
        int $inout = 0,
        ?string $time = null,
        ?string $aliasid = null,
        ?string $note = null
    ): array {
        if ($name === null) {
            $name = $this->getUserName($enrollId) ?? "User {$enrollId}";
        }
        $time = $time ?? date('Y-m-d H:i:s');

        // Next logindex
        $q = $this->pdo->query("SELECT COALESCE(MAX(logindex), 0) FROM attendance_logs");
        $maxIdx = (int) $q->fetchColumn();
        $q->closeCursor();
        $logIndex = $maxIdx + 1;

        $stmt = $this->pdo->prepare("
            INSERT INTO attendance_logs (logindex, enrollid, name, time, mode, inout, event, aliasid, note, marked)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0)
        ");
        $stmt->execute([$logIndex, $enrollId, $name, $time, $mode, $inout, $aliasid, $note]);

        return [
            'id'       => (int) $this->pdo->lastInsertId(),
            'logindex' => $logIndex,
            'enrollid' => $enrollId,
            'name'     => $name,
            'time'     => $time,
            'mode'     => $mode,
            'inout'    => $inout,
            'event'    => 0,
            'aliasid'  => $aliasid ?? "EMP{$enrollId}",
            'note'     => $note,
        ];
    }

    public function getNewLogs(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("
            SELECT logindex, enrollid, name, time, mode, inout, event, aliasid, note
            FROM attendance_logs 
            WHERE marked = 0 
            ORDER BY logindex ASC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function getAllLogs(int $page = 1, int $pageSize = 100, ?string $startTime = null, ?string $endTime = null): array
    {
        $offset = max(0, ($page - 1) * $pageSize);
        $sql = "SELECT logindex, enrollid, name, time, mode, inout, event, aliasid, note FROM attendance_logs WHERE 1=1";
        $params = [];

        if ($startTime) {
            $sql .= " AND time >= ?";
            $params[] = $startTime;
        }
        if ($endTime) {
            $sql .= " AND time <= ?";
            $params[] = $endTime;
        }

        $sql .= " ORDER BY logindex ASC LIMIT ? OFFSET ?";
        $params[] = $pageSize;
        $params[] = $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function markLogs(array $logIndices = []): int
    {
        if (empty($logIndices)) {
            $stmt = $this->pdo->prepare("UPDATE attendance_logs SET marked = 1 WHERE marked = 0");
            $stmt->execute();
            return $stmt->rowCount();
        }

        $placeholders = implode(',', array_fill(0, count($logIndices), '?'));
        $stmt = $this->pdo->prepare("UPDATE attendance_logs SET marked = 1 WHERE logindex IN ({$placeholders})");
        $stmt->execute($logIndices);
        return $stmt->rowCount();
    }

    public function cleanLog(): bool
    {
        $this->pdo->exec("DELETE FROM attendance_logs;");
        return true;
    }
}

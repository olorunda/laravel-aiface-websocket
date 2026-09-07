<?php

namespace AiFace\WebSocket\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Storage Service persisting AiFace devices, punch logs, and credentials.
 */
class StorageService
{
    protected array $config;
    protected bool $enabled;
    protected string $prefix;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->enabled = (bool) ($config['storage']['enabled'] ?? true);
        $this->prefix = (string) ($config['storage']['table_prefix'] ?? 'aiface_');
    }

    protected function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * Upsert device record on registration or heartbeat.
     */
    public function saveDevice(string $sn, string $ip, int $port, array $devInfo): void
    {
        if (!$this->enabled || empty($sn)) {
            return;
        }

        try {
            $tableName = $this->table('devices');
            $now = date('Y-m-d H:i:s');

            $data = [
                'ip' => $ip,
                'port' => $port,
                'model' => $devInfo['modelname'] ?? 'AiFace',
                'manufacturer' => $devInfo['manufacturer'] ?? '',
                'firmware' => $devInfo['firmware'] ?? '',
                'usersize' => (int) ($devInfo['usersize'] ?? 0),
                'facesize' => (int) ($devInfo['facesize'] ?? 0),
                'fpsize' => (int) ($devInfo['fpsize'] ?? 0),
                'logsize' => (int) ($devInfo['logsize'] ?? 0),
                'useduser' => (int) ($devInfo['useduser'] ?? 0),
                'usedface' => (int) ($devInfo['usedface'] ?? 0),
                'usedfp' => (int) ($devInfo['usedfp'] ?? 0),
                'usedlog' => (int) ($devInfo['usedlog'] ?? 0),
                'status' => 'online',
                'devinfo' => json_encode($devInfo),
                'last_seen_at' => $now,
                'updated_at' => $now,
            ];

            $exists = DB::table($tableName)->where('sn', $sn)->exists();
            if ($exists) {
                DB::table($tableName)->where('sn', $sn)->update($data);
            } else {
                $data['sn'] = $sn;
                $data['registered_at'] = $now;
                $data['created_at'] = $now;
                DB::table($tableName)->insert($data);
            }
        } catch (\Throwable $e) {
            Log::warning('StorageService::saveDevice failed: ' . $e->getMessage());
        }
    }

    /**
     * Update device online/offline status.
     */
    public function updateDeviceStatus(string $sn, string $status): void
    {
        if (!$this->enabled || empty($sn)) {
            return;
        }

        try {
            DB::table($this->table('devices'))
                ->where('sn', $sn)
                ->update([
                    'status' => $status,
                    'last_seen_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('StorageService::updateDeviceStatus failed: ' . $e->getMessage());
        }
    }

    /**
     * Batch insert attendance punch logs reported by device.
     */
    public function saveAttendanceLogs(string $sn, array $records): int
    {
        if (!$this->enabled || empty($records)) {
            return 0;
        }

        $saved = 0;
        $now = date('Y-m-d H:i:s');
        $storePhotos = (bool) ($this->config['storage']['store_photos'] ?? true);
        $photoDisk = $this->config['storage']['photo_disk'] ?? 'public';
        $photoPath = $this->config['storage']['photo_path'] ?? 'aiface_photos';

        try {
            $insertRows = [];
            foreach ($records as $record) {
                $imagePath = null;

                // If photo is attached in base64, save to storage disk
                if ($storePhotos && !empty($record['image']) && is_string($record['image'])) {
                    $imageData = base64_decode($record['image']);
                    if ($imageData) {
                        $filename = sprintf('%s/%s_%s_%s.jpg', $photoPath, $sn, $record['enrollid'] ?? '0', time() . '_' . bin2hex(random_bytes(2)));
                        Storage::disk($photoDisk)->put($filename, $imageData);
                        $imagePath = $filename;
                    }
                }

                $insertRows[] = [
                    'sn' => $sn,
                    'enrollid' => (string) ($record['enrollid'] ?? ''),
                    'name' => (string) ($record['name'] ?? ''),
                    'punch_time' => (string) ($record['time'] ?? $now),
                    'mode' => (int) ($record['mode'] ?? 0),
                    'inout' => (int) ($record['inout'] ?? 0),
                    'event' => (int) ($record['event'] ?? 0),
                    'aliasid' => (string) ($record['aliasid'] ?? ''),
                    'image_path' => $imagePath,
                    'note' => (string) ($record['note'] ?? ''),
                    'raw_data' => json_encode($record),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($insertRows)) {
                DB::table($this->table('attendance_logs'))->insert($insertRows);
                $saved = count($insertRows);
            }
        } catch (\Throwable $e) {
            Log::warning('StorageService::saveAttendanceLogs failed: ' . $e->getMessage());
        }

        return $saved;
    }

    /**
     * Persist user credential change reported via "senduser".
     */
    public function saveUserReport(string $sn, array $data): void
    {
        if (!$this->enabled || empty($data['enrollid'])) {
            return;
        }

        try {
            $enrollId = (string) $data['enrollid'];
            $now = date('Y-m-d H:i:s');
            $userTable = $this->table('users');
            $credTable = $this->table('user_credentials');

            // 1. Upsert base user
            $userData = [
                'name' => $data['name'] ?? '',
                'admin' => (int) ($data['admin'] ?? 0),
                'aliasid' => (string) ($data['aliasid'] ?? ''),
                'enable' => (int) ($data['enable'] ?? 1),
                'updated_at' => $now,
            ];

            if (isset($data['card'])) $userData['card'] = (string) $data['card'];
            if (isset($data['pwd']))  $userData['pwd'] = (string) $data['pwd'];

            $userExists = DB::table($userTable)->where('sn', $sn)->where('enrollid', $enrollId)->exists();
            if ($userExists) {
                DB::table($userTable)->where('sn', $sn)->where('enrollid', $enrollId)->update($userData);
            } else {
                $userData['sn'] = $sn;
                $userData['enrollid'] = $enrollId;
                $userData['created_at'] = $now;
                DB::table($userTable)->insert($userData);
            }

            // 2. Insert or update specific credential (backupnum)
            $backupNum = (int) ($data['backupnum'] ?? 0);
            $recordData = $data['record'] ?? '';

            $credExists = DB::table($credTable)
                ->where('sn', $sn)
                ->where('enrollid', $enrollId)
                ->where('backupnum', $backupNum)
                ->exists();

            if ($credExists) {
                DB::table($credTable)
                    ->where('sn', $sn)
                    ->where('enrollid', $enrollId)
                    ->where('backupnum', $backupNum)
                    ->update([
                        'record_data' => is_string($recordData) ? $recordData : json_encode($recordData),
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table($credTable)->insert([
                    'sn' => $sn,
                    'enrollid' => $enrollId,
                    'backupnum' => $backupNum,
                    'record_data' => is_string($recordData) ? $recordData : json_encode($recordData),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('StorageService::saveUserReport failed: ' . $e->getMessage());
        }
    }

    /**
     * Save GPS location reported by USB 4G terminal.
     */
    public function saveGpsLocation(string $sn, int $satellites, string $location, ?int $timestamp): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            DB::table($this->table('gps_logs'))->insert([
                'sn' => $sn,
                'satellites' => $satellites,
                'location' => $location,
                'recorded_at' => $timestamp ? date('Y-m-d H:i:s', (int) ($timestamp / 1000)) : date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('StorageService::saveGpsLocation failed: ' . $e->getMessage());
        }
    }

    /**
     * Audit log for commands dispatched and responses received.
     */
    public function logCommand(string $sn, string $cmd, array $request, array $response): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            DB::table($this->table('command_history'))->insert([
                'sn' => $sn,
                'cmd' => $cmd,
                'request_payload' => json_encode($request),
                'response_payload' => json_encode($response),
                'result' => (bool) ($response['result'] ?? false),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Silently ignore or debug
        }
    }

    /**
     * Persist a delayed / scheduled command.
     */
    public function saveScheduledCommand(array $task): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $taskId = $task['id'] ?? $task['task_id'] ?? uniqid('task_del_');
            $executeAt = isset($task['execute_at']) ? (is_numeric($task['execute_at']) ? (int) $task['execute_at'] : strtotime($task['execute_at'])) : time();

            DB::table($this->table('scheduled_commands'))->insert([
                'task_id'       => $taskId,
                'sn'            => $task['sn'],
                'cmd'           => $task['cmd'],
                'enrollid'      => isset($task['enrollid']) ? (string) $task['enrollid'] : null,
                'backupnum'     => isset($task['backupnum']) && $task['backupnum'] !== '' && $task['backupnum'] !== null ? (int) $task['backupnum'] : null,
                'delay_seconds' => (int) ($task['delay_seconds'] ?? 0),
                'execute_at'    => date('Y-m-d H:i:s', $executeAt),
                'status'        => 'pending',
                'payload'       => json_encode($task),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        } catch (\Throwable $e) {
            Log::warning('StorageService::saveScheduledCommand failed: ' . $e->getMessage());
        }
    }

    /**
     * Mark a scheduled command as executed.
     */
    public function markScheduledCommandExecuted(string $taskId, array $response = []): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            DB::table($this->table('scheduled_commands'))
                ->where('task_id', $taskId)
                ->update([
                    'status'     => 'executed',
                    'response'   => json_encode($response),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('StorageService::markScheduledCommandExecuted failed: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a pending scheduled command by taskId or by SN and enrollId.
     */
    public function cancelScheduledCommand(string $taskIdOrSn, ?string $enrollId = null): int
    {
        if (!$this->enabled) {
            return 0;
        }

        try {
            $query = DB::table($this->table('scheduled_commands'))
                ->where('status', 'pending');

            if ($enrollId !== null) {
                $query->where('sn', $taskIdOrSn)->where('enrollid', (string) $enrollId);
            } else {
                $query->where('task_id', $taskIdOrSn);
            }

            return (int) $query->update([
                'status'     => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('StorageService::cancelScheduledCommand failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retrieve all pending scheduled commands from database (e.g. upon daemon startup).
     *
     * @return array<string, array>
     */
    public function getPendingScheduledCommands(?string $sn = null): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $query = DB::table($this->table('scheduled_commands'))
                ->where('status', 'pending');

            if ($sn) {
                $query->where('sn', $sn);
            }

            $rows = $query->get();
            $tasks = [];
            foreach ($rows as $r) {
                $tasks[$r->task_id] = [
                    'id'            => $r->task_id,
                    'sn'            => $r->sn,
                    'cmd'           => $r->cmd,
                    'enrollid'      => $r->enrollid,
                    'backupnum'     => $r->backupnum,
                    'delay_seconds' => (int) $r->delay_seconds,
                    'execute_at'    => strtotime($r->execute_at),
                    'created_at'    => strtotime($r->created_at),
                ];
            }
            return $tasks;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

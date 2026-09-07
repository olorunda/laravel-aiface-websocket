<?php

namespace AiFace\WebSocket\Core;

/**
 * AiFace TimyTeco WebSocket Protocol Constants, Definitions and Encoders
 */
class Protocol
{
    // ==========================================
    // BACKUPNUM DEFINITIONS (Credential Types)
    // ==========================================
    public const BACKUP_FP_0 = 0;
    public const BACKUP_FP_1 = 1;
    public const BACKUP_FP_2 = 2;
    public const BACKUP_FP_3 = 3;
    public const BACKUP_FP_4 = 4;
    public const BACKUP_FP_5 = 5;
    public const BACKUP_FP_6 = 6;
    public const BACKUP_FP_7 = 7;
    public const BACKUP_FP_8 = 8;
    public const BACKUP_FP_9 = 9;

    public const BACKUP_PASSWORD = 10;
    public const BACKUP_CARD = 11;

    public const BACKUP_PALM_LEFT = 40;
    public const BACKUP_PALM_RIGHT = 41;

    public const BACKUP_FACE_PHOTO = 50;   // Base64 JPG image (bFace_template = 0)
    public const BACKUP_FACE_FEATURE = 51; // Base64 feature template (bFace_template = 1)

    // ==========================================
    // VERIFY MODES IN LOGS (mode field)
    // ==========================================
    public const LOG_MODE_PASSWORD = 0;
    public const LOG_MODE_FINGERPRINT = 1;
    public const LOG_MODE_CARD = 2;
    public const LOG_MODE_FACE = 3;
    public const LOG_MODE_FINGER_VEIN = 4;
    public const LOG_MODE_PALM_VEIN = 5;
    public const LOG_MODE_QRCODE = 6;
    public const LOG_MODE_OTHER = 7;

    // ==========================================
    // IN / OUT STATUS (inout field)
    // ==========================================
    public const INOUT_CHECK_IN = 0;
    public const INOUT_CHECK_OUT = 1;
    public const INOUT_BREAK_OUT = 2;
    public const INOUT_BREAK_IN = 3;
    public const INOUT_OT_IN = 4;
    public const INOUT_OT_OUT = 5;

    // ==========================================
    // REASON / ERROR CODES
    // ==========================================
    public const REASON_GENERIC_FAILURE = 1;
    public const REASON_MD5_CHECK_ERROR = 2;
    public const REASON_OPERATION_FAILED = 3;
    public const REASON_INVALID_FORMAT = 4;
    public const REASON_NOT_FOUND = 5;

    /**
     * Get human-readable description for backupnum.
     */
    public static function getBackupNumDescription(int $backupnum): string
    {
        return match ($backupnum) {
            self::BACKUP_FP_0 => 'Fingerprint Slot #0 (First Finger)',
            self::BACKUP_FP_1 => 'Fingerprint Slot #1',
            self::BACKUP_FP_2 => 'Fingerprint Slot #2',
            self::BACKUP_FP_3 => 'Fingerprint Slot #3',
            self::BACKUP_FP_4 => 'Fingerprint Slot #4',
            self::BACKUP_FP_5 => 'Fingerprint Slot #5',
            self::BACKUP_FP_6 => 'Fingerprint Slot #6',
            self::BACKUP_FP_7 => 'Fingerprint Slot #7',
            self::BACKUP_FP_8 => 'Fingerprint Slot #8',
            self::BACKUP_FP_9 => 'Fingerprint Slot #9',
            self::BACKUP_PASSWORD => 'Password / PIN',
            self::BACKUP_CARD => 'RFID / IC Card',
            self::BACKUP_PALM_LEFT => 'Left Palm Vein',
            self::BACKUP_PALM_RIGHT => 'Right Palm Vein',
            self::BACKUP_FACE_PHOTO => 'Face Photo (JPG)',
            self::BACKUP_FACE_FEATURE => 'Face Feature Template',
            default => 'Unknown (' . $backupnum . ')',
        };
    }

    /**
     * Get simplified credential type for backupnum.
     */
    public static function getCredentialType(int $backupnum): string
    {
        if ($backupnum >= 0 && $backupnum <= 9) {
            return 'fingerprint';
        }
        return match ($backupnum) {
            self::BACKUP_PASSWORD => 'password',
            self::BACKUP_CARD => 'card',
            self::BACKUP_PALM_LEFT, self::BACKUP_PALM_RIGHT => 'palm',
            self::BACKUP_FACE_PHOTO => 'face_photo',
            self::BACKUP_FACE_FEATURE => 'face_feature',
            default => 'other',
        };
    }

    /**
     * Decode AiFace 0-compressed fingerprint hex string.
     * Consecutive 0x00 bytes are encoded as (N) where N is the count.
     * Example: 'ff(5)aabb' -> 'ff0000000000aabb'
     */
    public static function decodeFingerprintCompression(string $compressed): string
    {
        return preg_replace_callback('/\((\d+)\)/', function ($matches) {
            $count = (int) $matches[1];
            return str_repeat('00', $count);
        }, $compressed);
    }

    /**
     * Encode raw fingerprint hex string with 0-compression.
     * Consecutive '00' sequences >= 3 bytes are converted to (N).
     * Example: 'ff0000000000aabb' -> 'ff(5)aabb'
     */
    public static function encodeFingerprintCompression(string $hex): string
    {
        return preg_replace_callback('/(00){3,}/', function ($matches) {
            $count = strlen($matches[0]) / 2;
            return '(' . $count . ')';
        }, $hex);
    }

    /**
     * Format verification mode bitmask into human-readable string.
     */
    public static function formatVerifyMode(int $mask): string
    {
        if ($mask === 255 || $mask === 0) {
            return 'Default / Any (Card, Face, FP, Pwd)';
        }

        $modes = [];
        if ($mask & 1)  $modes[] = 'Card';
        if ($mask & 2)  $modes[] = 'Fingerprint';
        if ($mask & 4)  $modes[] = 'Password';
        if ($mask & 8)  $modes[] = 'Face';
        if ($mask & 16) $modes[] = 'Palm';

        return empty($modes) ? 'None (Disabled)' : implode(' + ', $modes);
    }

    /**
     * Get description for punch verification mode in attendance logs.
     */
    public static function getLogModeDescription(int $mode): string
    {
        return match ($mode) {
            self::LOG_MODE_PASSWORD => 'Password',
            self::LOG_MODE_FINGERPRINT => 'Fingerprint',
            self::LOG_MODE_CARD => 'Card',
            self::LOG_MODE_FACE => 'Face Recognition',
            self::LOG_MODE_FINGER_VEIN => 'Finger Vein',
            self::LOG_MODE_PALM_VEIN => 'Palm Vein',
            self::LOG_MODE_QRCODE => 'QR Code',
            self::LOG_MODE_OTHER => 'Other / Manual',
            default => 'Unknown (' . $mode . ')',
        };
    }

    /**
     * Get description for error reason code.
     */
    public static function getReasonDescription(int $reason): string
    {
        return match ($reason) {
            self::REASON_GENERIC_FAILURE => 'Generic failure or parameter error',
            self::REASON_MD5_CHECK_ERROR => 'MD5 or checksum verification error',
            self::REASON_OPERATION_FAILED => 'Operation failed on device',
            self::REASON_INVALID_FORMAT => 'Invalid data format or unsupported parameter',
            self::REASON_NOT_FOUND => 'Record or user not found',
            default => 'Device error code ' . $reason,
        };
    }
}

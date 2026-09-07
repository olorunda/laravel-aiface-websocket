<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Core\Protocol;
use PHPUnit\Framework\TestCase;

class ProtocolTest extends TestCase
{
    public function test_decode_fingerprint_0_compression(): void
    {
        // 'ff(5)aabb' -> 'ff' + 5 pairs of '00' + 'aabb'
        $compressed = 'ff(5)aabb';
        $decoded = Protocol::decodeFingerprintCompression($compressed);
        $this->assertEquals('ff0000000000aabb', $decoded);

        // Multiple compressed sections
        $multiple = 'aa(3)bb(2)cc';
        $decodedMultiple = Protocol::decodeFingerprintCompression($multiple);
        $this->assertEquals('aa000000bb0000cc', $decodedMultiple);
    }

    public function test_encode_fingerprint_0_compression(): void
    {
        $raw = 'ff0000000000aabb';
        $encoded = Protocol::encodeFingerprintCompression($raw);
        $this->assertEquals('ff(5)aabb', $encoded);
    }

    public function test_backupnum_descriptions_and_types(): void
    {
        $this->assertEquals('Fingerprint Slot #0 (First Finger)', Protocol::getBackupNumDescription(0));
        $this->assertEquals('fingerprint', Protocol::getCredentialType(0));
        $this->assertEquals('fingerprint', Protocol::getCredentialType(9));

        $this->assertEquals('Password / PIN', Protocol::getBackupNumDescription(Protocol::BACKUP_PASSWORD));
        $this->assertEquals('password', Protocol::getCredentialType(Protocol::BACKUP_PASSWORD));

        $this->assertEquals('RFID / IC Card', Protocol::getBackupNumDescription(Protocol::BACKUP_CARD));
        $this->assertEquals('card', Protocol::getCredentialType(Protocol::BACKUP_CARD));

        $this->assertEquals('Left Palm Vein', Protocol::getBackupNumDescription(Protocol::BACKUP_PALM_LEFT));
        $this->assertEquals('palm', Protocol::getCredentialType(Protocol::BACKUP_PALM_LEFT));

        $this->assertEquals('Face Photo (JPG)', Protocol::getBackupNumDescription(Protocol::BACKUP_FACE_PHOTO));
        $this->assertEquals('face_photo', Protocol::getCredentialType(Protocol::BACKUP_FACE_PHOTO));

        $this->assertEquals('Face Feature Template', Protocol::getBackupNumDescription(Protocol::BACKUP_FACE_FEATURE));
        $this->assertEquals('face_feature', Protocol::getCredentialType(Protocol::BACKUP_FACE_FEATURE));
    }

    public function test_log_mode_descriptions(): void
    {
        $this->assertEquals('Face Recognition', Protocol::getLogModeDescription(Protocol::LOG_MODE_FACE));
        $this->assertEquals('Fingerprint', Protocol::getLogModeDescription(Protocol::LOG_MODE_FINGERPRINT));
        $this->assertEquals('Card', Protocol::getLogModeDescription(Protocol::LOG_MODE_CARD));
        $this->assertEquals('Password', Protocol::getLogModeDescription(Protocol::LOG_MODE_PASSWORD));
        $this->assertEquals('QR Code', Protocol::getLogModeDescription(Protocol::LOG_MODE_QRCODE));
    }

    public function test_verify_mode_formatting(): void
    {
        $this->assertStringContainsString('Default', Protocol::formatVerifyMode(255));
        $this->assertStringContainsString('Card', Protocol::formatVerifyMode(1));
        $this->assertStringContainsString('Card + Fingerprint', Protocol::formatVerifyMode(3));
    }

    public function test_reason_descriptions(): void
    {
        $this->assertStringContainsString('Generic failure', Protocol::getReasonDescription(1));
        $this->assertStringContainsString('MD5', Protocol::getReasonDescription(2));
        $this->assertStringContainsString('Operation failed', Protocol::getReasonDescription(3));
    }
}

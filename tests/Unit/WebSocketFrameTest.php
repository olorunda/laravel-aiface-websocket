<?php

namespace AiFace\WebSocket\Tests\Unit;

use AiFace\WebSocket\Core\WebSocketFrame;
use PHPUnit\Framework\TestCase;

class WebSocketFrameTest extends TestCase
{
    public function test_encode_and_decode_unmasked_text_frame(): void
    {
        $payload = '{"cmd":"getdevinfo","sn":"LF00000001"}';
        $frame = WebSocketFrame::encode($payload, WebSocketFrame::OPCODE_TEXT, false);

        $buffer = $frame;
        $decoded = WebSocketFrame::decode($buffer);

        $this->assertNotNull($decoded);
        $this->assertEquals(WebSocketFrame::OPCODE_TEXT, $decoded['opcode']);
        $this->assertEquals($payload, $decoded['payload']);
        $this->assertTrue($decoded['fin']);
        $this->assertEquals('', $buffer, 'Buffer should be completely consumed');
    }

    public function test_encode_and_decode_masked_frame(): void
    {
        $payload = '{"cmd":"reg","sn":"LF12345678"}';
        $frame = WebSocketFrame::encode($payload, WebSocketFrame::OPCODE_TEXT, true);

        $buffer = $frame;
        $decoded = WebSocketFrame::decode($buffer);

        $this->assertNotNull($decoded);
        $this->assertTrue($decoded['masked']);
        $this->assertEquals($payload, $decoded['payload']);
    }

    public function test_encode_ping_and_pong_frames(): void
    {
        $pingFrame = WebSocketFrame::encodePing('heartbeat');
        $buffer = $pingFrame;
        $decodedPing = WebSocketFrame::decode($buffer);

        $this->assertNotNull($decodedPing);
        $this->assertEquals(WebSocketFrame::OPCODE_PING, $decodedPing['opcode']);
        $this->assertEquals('heartbeat', $decodedPing['payload']);

        $pongFrame = WebSocketFrame::encodePong('heartbeat');
        $buffer2 = $pongFrame;
        $decodedPong = WebSocketFrame::decode($buffer2);

        $this->assertNotNull($decodedPong);
        $this->assertEquals(WebSocketFrame::OPCODE_PONG, $decodedPong['opcode']);
        $this->assertEquals('heartbeat', $decodedPong['payload']);
    }

    public function test_medium_payload_16bit_length(): void
    {
        // Greater than 125 bytes, less than 65536 bytes
        $payload = str_repeat('A', 500);
        $frame = WebSocketFrame::encode($payload, WebSocketFrame::OPCODE_TEXT, false);

        $buffer = $frame;
        $decoded = WebSocketFrame::decode($buffer);

        $this->assertNotNull($decoded);
        $this->assertEquals(500, $decoded['length']);
        $this->assertEquals($payload, $decoded['payload']);
    }

    public function test_partial_frame_waits_for_complete_payload(): void
    {
        $payload = 'Complete Payload';
        $frame = WebSocketFrame::encode($payload, WebSocketFrame::OPCODE_TEXT, false);

        // Truncate frame bytes to simulate TCP fragmentation
        $partial = substr($frame, 0, 5);
        $buffer = $partial;
        $decoded = WebSocketFrame::decode($buffer);

        $this->assertNull($decoded, 'Should return null when frame is incomplete');
        $this->assertEquals($partial, $buffer, 'Buffer should not be modified on incomplete frame');

        // Append remaining bytes
        $buffer .= substr($frame, 5);
        $decodedFull = WebSocketFrame::decode($buffer);

        $this->assertNotNull($decodedFull);
        $this->assertEquals($payload, $decodedFull['payload']);
    }
}

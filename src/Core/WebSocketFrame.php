<?php

namespace AiFace\WebSocket\Core;

/**
 * RFC 6455 WebSocket Framing & Codec
 */
class WebSocketFrame
{
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT         = 0x1;
    public const OPCODE_BINARY       = 0x2;
    public const OPCODE_CLOSE        = 0x8;
    public const OPCODE_PING         = 0x9;
    public const OPCODE_PONG         = 0xA;

    /**
     * Encode payload into RFC 6455 WebSocket frame.
     * Server-to-client frames are unmasked by standard.
     */
    public static function encode(string $payload, int $opcode = self::OPCODE_TEXT, bool $masked = false): string
    {
        $fin = 0x80;
        $byte1 = $fin | ($opcode & 0x0F);
        $length = strlen($payload);

        $maskBit = $masked ? 0x80 : 0x00;

        if ($length <= 125) {
            $header = chr($byte1) . chr($maskBit | $length);
        } elseif ($length <= 65535) {
            $header = chr($byte1) . chr($maskBit | 126) . pack('n', $length);
        } else {
            // 64-bit length
            $header = chr($byte1) . chr($maskBit | 127) . pack('J', $length);
        }

        if ($masked) {
            $maskingKey = random_bytes(4);
            $maskedPayload = '';
            for ($i = 0; $i < $length; $i++) {
                $maskedPayload .= $payload[$i] ^ $maskingKey[$i % 4];
            }
            return $header . $maskingKey . $maskedPayload;
        }

        return $header . $payload;
    }

    /**
     * Decode a frame from stream buffer.
     * Returns array with frame info or null if buffer has incomplete frame.
     * Consumes decoded bytes from $buffer by reference.
     */
    public static function decode(string &$buffer): ?array
    {
        $bufferLen = strlen($buffer);
        if ($bufferLen < 2) {
            return null;
        }

        $byte1 = ord($buffer[0]);
        $byte2 = ord($buffer[1]);

        $fin = (bool) ($byte1 & 0x80);
        $opcode = $byte1 & 0x0F;
        $isMasked = (bool) ($byte2 & 0x80);
        $payloadLen = $byte2 & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if ($bufferLen < $offset + 2) {
                return null;
            }
            $payloadLen = unpack('n', substr($buffer, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLen === 127) {
            if ($bufferLen < $offset + 8) {
                return null;
            }
            $payloadLen = unpack('J', substr($buffer, $offset, 8))[1];
            $offset += 8;
        }

        $maskKey = '';
        if ($isMasked) {
            if ($bufferLen < $offset + 4) {
                return null;
            }
            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($bufferLen < $offset + $payloadLen) {
            // Waiting for complete payload
            return null;
        }

        $rawPayload = substr($buffer, $offset, $payloadLen);
        // Advance buffer
        $buffer = substr($buffer, $offset + $payloadLen);

        if ($isMasked && $payloadLen > 0) {
            $payload = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $payload .= $rawPayload[$i] ^ $maskKey[$i % 4];
            }
        } else {
            $payload = $rawPayload;
        }

        return [
            'fin' => $fin,
            'opcode' => $opcode,
            'masked' => $isMasked,
            'length' => $payloadLen,
            'payload' => $payload,
        ];
    }

    public static function encodeClose(int $code = 1000, string $reason = ''): string
    {
        return self::encode(pack('n', $code) . $reason, self::OPCODE_CLOSE);
    }

    public static function encodePing(string $payload = ''): string
    {
        return self::encode($payload, self::OPCODE_PING);
    }

    public static function encodePong(string $payload = ''): string
    {
        return self::encode($payload, self::OPCODE_PONG);
    }
}

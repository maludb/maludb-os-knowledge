<?php
declare(strict_types=1);

/**
 * MaluMail (optional: no MALUMAIL_API_KEY means no mail, never an error elsewhere): one outbound send (`/v1/send`, bearer MALUMAIL_API_KEY; MALUMAIL_API_URL points the proofs at a stub); Knowledge reads no mailbox and
 * receives no mail, so there is no webhook and no mailbox MCP here.
 */

/** Send one email. Answers ['status' => int, 'body' => array, 'message_id' => ?string]; throws on a missing key or a transport error (never on an HTTP status). */
function malumail_send(array $mail): array
{
    $key = (string) env('MALUMAIL_API_KEY', '');
    if ($key === '') {
        throw new RuntimeException('MALUMAIL_API_KEY is not configured.');
    }
    $ch = curl_init(rtrim((string) env('MALUMAIL_API_URL', 'https://api.malumail.com'), '/') . '/v1/send');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($mail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $raw === false) {
        throw new RuntimeException('MaluMail transport error.');
    }
    $body = json_decode((string) $raw, true);
    $body = is_array($body) ? $body : [];
    return ['status' => $status, 'body' => $body, 'message_id' => isset($body['message_id']) ? (string) $body['message_id'] : null];
}

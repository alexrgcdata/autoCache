<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/src/http.php';
function checkContact(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}
function rejectsContact(callable $action, int $status): void {
    try { $action(); } catch (rgcCache_Error $error) {
        checkContact($error->httpStatus === $status, "rejects with HTTP $status"); return;
    }
    throw new RuntimeException('Expected rejection');
}
$session = [];
$response = rgcCache_dispatch('GET', '/rgccache/api/v1/admin/contact/session', '', ['base_path'=>'/rgccache'], '', '', 'application/json', null, '', $session);
$csrf = $response['body']['csrf_token'];
checkContact($response['status'] === 200 && strlen($csrf) === 64 && rgcCache_adminIdentity($session) === null, 'contact session grants no admin access');
$details = ['name'=>'Test Person', 'email'=>'person@example.test', 'phone'=>'555-0100', 'kind'=>'project', 'message'=>"A fictional project for local testing.\nSecond line.", 'website'=>''];
$body = json_encode($details);
$noop = static fn() => null;
$calls = [];
$mail = static function ($to, $subject, $text, $headers) use (&$calls): bool { $calls[] = compact('to', 'subject', 'text', 'headers'); return true; };
rejectsContact(function () use (&$session, $body, $mail, $noop) { rgcCache_contactSend($body, 'wrong', $session, [], $mail, $noop); }, 403);
foreach ([['email'=>"a@example.test\r\nBcc: attacker@example.test"], ['name'=>"Header\nInjection"], ['website'=>'spam'], ['message'=>'short'], ['email'=>'bad-email'], ['kind'=>'unknown'], ['to'=>'attacker@example.test']] as $invalid) {
    rejectsContact(static fn() => rgcCache_contactMessage(json_encode(array_replace($details, $invalid))), 422);
}
checkContact(count($calls) === 0, 'invalid requests never reach mail transport');
$mailConfig = ['contact'=>['from_email'=>'cache@example.test', 'to_email'=>'owner@example.test']];
rejectsContact(function () use (&$session, $body, $csrf, $mail, $noop) { rgcCache_contactSend($body, $csrf, $session, [], $mail, $noop); }, 503);
checkContact(count($calls) === 0, 'unconfigured community install cannot send email');
$result = rgcCache_contactSend($body, $csrf, $session, $mailConfig, $mail, $noop);
checkContact($result['accepted'] === true && count($calls) === 1, 'valid form hands off one message');
checkContact($calls[0]['to'] === 'owner@example.test' && $calls[0]['headers']['Reply-To'] === $details['email'], 'recipient configured server-side and reply goes to visitor');
checkContact($calls[0]['headers']['From'] === 'cache@example.test' && str_contains(base64_decode($calls[0]['text']), $details['message']), 'sender configured and multiline UTF-8 text preserved');
rejectsContact(function () use (&$session, $body, $csrf, $mail, $noop) { rgcCache_contactSend($body, $csrf, $session, [], $mail, $noop); }, 403);
$csrf = rgcCache_contactSession($session)['csrf_token'];
rejectsContact(function () use (&$session, $body, $csrf, $noop, $mailConfig) { rgcCache_contactSend($body, $csrf, $session, $mailConfig, static fn() => false, $noop); }, 503);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rgccache-contact-test-' . bin2hex(random_bytes(8));
mkdir($directory);
try {
    for ($i = 0; $i < 5; $i++) { rgcCache_contactRateLimit('192.0.2.1', $directory); }
    rejectsContact(static fn() => rgcCache_contactRateLimit('192.0.2.1', $directory), 429);
    rgcCache_contactRateLimit('192.0.2.2', $directory);
    checkContact(true, 'IP throttle persists across requests and isolates clients');
} finally {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') as $file) { unlink($file); }
    rmdir($directory);
}
echo "Contact tests passed; no real email sent.\n";

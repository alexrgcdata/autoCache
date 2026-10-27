<?php
declare(strict_types=1);

// Public contact form: separate CSRF token; never grants administrator access.
function rgcCache_contactSession(array &$session): array
{
    if (!is_string($session['rgcCache_contact_csrf'] ?? null)) {
        $session['rgcCache_contact_csrf'] = bin2hex(random_bytes(32));
    }
    return ['csrf_token'=>$session['rgcCache_contact_csrf']];
}

function rgcCache_contactMessage(string $body): array
{
    if ($body === '' || strlen($body) > 20000) { throw new rgcCache_Error('contact_request_too_large', 413); }
    $input = rgcCache_json($body);
    $limits = ['name'=>120, 'email'=>254, 'phone'=>60, 'kind'=>20, 'message'=>6000, 'website'=>200];
    if (!$input instanceof stdClass || array_diff(array_keys(get_object_vars($input)), array_keys($limits))) {
        throw new rgcCache_Error('invalid_contact_details', 422);
    }
    $values = [];
    foreach ($limits as $key=>$limit) {
        $value = $input->{$key} ?? '';
        if (!is_string($value) || strlen($value) > $limit || str_contains($value, "\0")) {
            throw new rgcCache_Error('invalid_contact_details', 422);
        }
        $values[$key] = trim($value);
    }
    if ($values['website'] !== '') { throw new rgcCache_Error('contact_submission_rejected', 422); }
    if ($values['name'] === '' || strlen($values['message']) < 10
        || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $values['email'] . $values['name'] . $values['phone'])
        || !in_array($values['kind'], ['project', 'support'], true)) {
        throw new rgcCache_Error('provide_name_email_and_details', 422);
    }
    return $values;
}

// Fixed-size time-window counters, shared across sessions, outside the web root.
function rgcCache_contactRateLimit(string $ip, ?string $directory = null): void
{
    $directory ??= sys_get_temp_dir();
    $path = $directory . DIRECTORY_SEPARATOR . 'rgccache-contact-' . hash('sha256', __DIR__) . '.json';
    $file = @fopen($path, 'c+');
    if (!$file) { throw new rgcCache_Error('contact_service_unavailable', 503); }
    try {
        if (!flock($file, LOCK_EX)) { throw new rgcCache_Error('contact_service_unavailable', 503); }
        $text = stream_get_contents($file);
        $state = $text === '' ? [] : json_decode((string)$text, true);
        if (!is_array($state)) { throw new rgcCache_Error('contact_service_unavailable', 503); }
        $now = time();
        $state = array_filter($state, static fn($entry) => is_array($entry) && ($entry['until'] ?? 0) > $now);
        $key = hash('sha256', $ip);
        $entry = $state[$key] ?? ['until'=>$now + 900, 'count'=>0];
        $global = $state['global'] ?? ['until'=>$now + 900, 'count'=>0];
        if ($entry['count'] >= 5 || $global['count'] >= 30) { throw new rgcCache_Error('contact_limit_reached_try_later', 429); }
        $entry['count']++; $global['count']++;
        $state[$key] = $entry; $state['global'] = $global;
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
            throw new rgcCache_Error('contact_service_unavailable', 503);
        }
    } finally { fclose($file); }
}

function rgcCache_contactSend(string $body, string $csrf, array &$session, array $config, ?callable $mailer = null, ?callable $limiter = null): array
{
    $expected = $session['rgcCache_contact_csrf'] ?? '';
    if (!is_string($expected) || $expected === '' || $csrf === '' || !hash_equals($expected, $csrf)) {
        throw new rgcCache_Error('contact_form_expired_reopen_it', 403);
    }
    $values = rgcCache_contactMessage($body);
    // Community packages must never route messages to a previous installation.
    $from = $config['contact']['from_email'] ?? '';
    $to = $config['contact']['to_email'] ?? '';
    if (!is_string($to) || preg_match('/[\r\n]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new rgcCache_Error('contact_recipient_unavailable', 503);
    }
    if (!is_string($from) || preg_match('/[\r\n]/', $from) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new rgcCache_Error('contact_sender_unavailable', 503);
    }
    ($limiter ?? static fn() => rgcCache_contactRateLimit((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')))();
    $subject = $values['kind'] === 'project' ? 'rgcCache - Project inquiry' : 'rgcCache - Support inquiry';
    $message = "Name: {$values['name']}\nEmail: {$values['email']}\nPhone: " . ($values['phone'] ?: 'Not supplied')
        . "\nRequest: {$values['kind']}\n\nDetails:\n{$values['message']}\n";
    $headers = ['From'=>$from, 'Reply-To'=>$values['email'], 'MIME-Version'=>'1.0',
        'Content-Type'=>'text/plain; charset=UTF-8', 'Content-Transfer-Encoding'=>'base64'];
    $send = $mailer ?? static fn($to, $subject, $message, $headers) => mail($to, $subject, $message, $headers);
    try { $accepted = $send($to, $subject, chunk_split(base64_encode($message), 76, "\r\n"), $headers); }
    catch (Throwable $error) { throw new rgcCache_Error('contact_email_unavailable_try_email_or_phone', 503); }
    if (!$accepted) { throw new rgcCache_Error('contact_email_unavailable_try_email_or_phone', 503); }
    // Prevent replay of a successful form submission.
    unset($session['rgcCache_contact_csrf']);
    return ['accepted'=>true];
}

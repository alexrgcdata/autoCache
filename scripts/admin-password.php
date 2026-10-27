<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function rgcCache_hiddenPassword(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    if (PHP_OS_FAMILY === 'Windows') {
        $command = 'powershell.exe -NoProfile -Command "$p=Read-Host -AsSecureString; $b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); try {[Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b)}"';
        $password = shell_exec($command);
        fwrite(STDOUT, PHP_EOL);
        if (!is_string($password)) { throw new RuntimeException('Unable to read password'); }
        return rtrim($password, "\r\n");
    }
    $stty = shell_exec('stty -g 2>/dev/null');
    if (!is_string($stty) || trim($stty) === '') { throw new RuntimeException('A terminal is required'); }
    shell_exec('stty -echo');
    try { $password = fgets(STDIN); }
    finally { shell_exec('stty ' . escapeshellarg(trim($stty))); fwrite(STDOUT, PHP_EOL); }
    if (!is_string($password)) { throw new RuntimeException('Unable to read password'); }
    return rtrim($password, "\r\n");
}

try {
    $first = rgcCache_hiddenPassword('Administrator password: ');
    $second = rgcCache_hiddenPassword('Confirm password: ');
    if ($first === '' || strlen($first) < 12 || strlen($first) > 1024) { throw new RuntimeException('Use 12 to 1024 characters'); }
    if (!hash_equals($first, $second)) { throw new RuntimeException('Passwords do not match'); }
    fwrite(STDOUT, password_hash($first, PASSWORD_DEFAULT) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Password hash not generated: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

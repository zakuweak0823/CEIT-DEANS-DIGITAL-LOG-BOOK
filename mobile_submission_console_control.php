<?php
function mobile_submission_console_state_path()
{
    $projectRoot = realpath(__DIR__) ?: __DIR__;
    $fileName = 'dit_logbook_mobile_submission_' . substr(hash('sha256', $projectRoot), 0, 16) . '.state';
    return rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . $fileName;
}

function mobile_submission_console_is_enabled()
{
    $state = @file_get_contents(mobile_submission_console_state_path());
    return trim((string)$state) === '1';
}

function mobile_submission_console_lock_path()
{
    $projectRoot = realpath(__DIR__) ?: __DIR__;
    $fileName = 'dit_logbook_mobile_submission_' . substr(hash('sha256', $projectRoot), 0, 16) . '.lock';
    return rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . $fileName;
}

function mobile_submission_console_is_running()
{
    $lockHandle = @fopen(mobile_submission_console_lock_path(), 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Could not check whether the CMD log is running.');
    }

    $isRunning = !flock($lockHandle, LOCK_EX | LOCK_NB);
    if (!$isRunning) {
        flock($lockHandle, LOCK_UN);
    }
    fclose($lockHandle);
    return $isRunning;
}

function set_mobile_submission_console_enabled($enabled)
{
    $result = file_put_contents(mobile_submission_console_state_path(), $enabled ? '1' : '0', LOCK_EX);
    if ($result === false) {
        throw new RuntimeException('Could not update the CMD log state file.');
    }
}

function start_mobile_submission_console()
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('Automatic CMD launch is available only on Windows.');
    }

    if (mobile_submission_console_is_running()) {
        return;
    }

    $phpCandidates = [
        PHP_BINARY,
        dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
        PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe',
    ];
    $phpExecutable = '';
    foreach ($phpCandidates as $candidate) {
        if (strtolower(basename($candidate)) === 'php.exe' && is_file($candidate)) {
            $phpExecutable = $candidate;
            break;
        }
    }
    $helperPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'mobile_submission_console.php');
    if ($phpExecutable === '' || $helperPath === false) {
        throw new RuntimeException('Could not find PHP or the mobile submission CMD helper.');
    }

    $command = 'start "DIT Logbook - Mobile Submission Log" cmd.exe /c ""'
        . $phpExecutable . '" "' . $helperPath . '""';
    $process = @popen($command, 'r');
    if ($process === false) {
        throw new RuntimeException('Windows could not start the CMD log window.');
    }
    pclose($process);
}

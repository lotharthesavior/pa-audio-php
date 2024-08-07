<?php

// Function to find default source
function get_default_source() {
    // Get default source
    $default_source = trim(shell_exec("pacmd list-sources | grep '\\* index' -A 1 | grep 'name:' | awk -F '<|>' '{print $2}'"));

    return $default_source;
}

// Handle signals to properly terminate the recording
declare(ticks = 1);
pcntl_signal(SIGINT, function($signo) {
    global $handle, $rawFile;

    // Close the raw file and process handle if open
    if ($rawFile) {
        fclose($rawFile);
    }
    if ($handle) {
        pclose($handle);
    }

    // Convert raw audio to MP4 using ffmpeg
    if (file_exists("recorded_audio.raw")) {
        $command = "ffmpeg -f s16le -ar 44100 -ac 2 -i recorded_audio.raw -c:a aac -b:a 128k recorded_audio.mp4";
        exec($command, $output, $return_var);

        if ($return_var === 0) {
            echo "Recording stopped and file saved as recorded_audio.mp4.\n";
            unlink("recorded_audio.raw");
        } else {
            echo "Error converting audio to MP4.\n";
        }
    }
    exit;
});

// Get default source
$default_source = get_default_source();

if (!$default_source) {
    echo "Unable to find default source.\n";
    exit(1);
}

$handle = null;
$rawFile = null;

// Set up the command to capture audio from the PulseAudio source
$command = "parec -d {$default_source} --format=s16le --rate=44100 --channels=2";

// Open process to run the command
$handle = popen($command, 'r');

if ($handle) {
    // Open raw file to save the recorded audio
    $rawFile = fopen("recorded_audio.raw", 'w');

    if (!$rawFile) {
        echo "Unable to open raw file for writing: recorded_audio.raw\n";
        exit(1);
    }
} else {
    echo "Unable to execute parec command for source: {$default_source}\n";
    exit(1);
}

// Function to process the handle
function process_handle($handle, $rawFile): void {
    while (!feof($handle)) {
        $buffer = fread($handle, 8192);
        fwrite($rawFile, $buffer);
    }
    fclose($rawFile);
    pclose($handle);
}

// Fork a process to handle the recording
$pid = pcntl_fork();

if ($pid == -1) {
    die('Could not fork');
} elseif ($pid) {
    // Parent process continues
} else {
    // Child process handles the recording
    process_handle($handle, $rawFile);
    exit(0); // Child process exit
}

echo "Recording started. Press Ctrl+C to stop.\n";

// Keep the script running to handle signal interruption
while (true) {
    sleep(1);
}

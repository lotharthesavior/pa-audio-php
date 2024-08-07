<?php

// Function to find default source and sink
function get_default_devices() {
    // Get default source
    $default_source = trim(shell_exec("pacmd list-sources | grep '\\* index' -A 1 | grep 'name:' | awk -F '<|>' '{print $2}'"));

    // Get default sink
    $default_sink = trim(shell_exec("pacmd list-sinks | grep '\\* index' -A 1 | grep 'name:' | awk -F '<|>' '{print $2}'"));

    return [$default_source, $default_sink];
}

// Handle signals to properly terminate the recording
declare(ticks = 1);
pcntl_signal(SIGINT, function($signo) {
    global $handles, $rawFiles;

    // Close the raw files and process handles if open
    foreach ($rawFiles as $rawFile) {
        if ($rawFile) {
            fclose($rawFile);
        }
    }
    foreach ($handles as $handle) {
        if ($handle) {
            pclose($handle);
        }
    }

    // Convert raw audio to MP4 using ffmpeg
    foreach ($rawFiles as $index => $rawFile) {
        if (!file_exists("recorded_audio{$index}.raw")) {
            continue;
        }

        $command = "ffmpeg -f s16le -ar 44100 -ac 2 -i recorded_audio{$index}.raw -c:a aac -b:a 128k recorded_audio{$index}.mp4";
        exec($command, $output, $return_var);

        if ($return_var === 0) {
            echo "Recording stopped and file saved as recorded_audio{$index}.mp4.\n";
            unlink("recorded_audio{$index}.raw");
        } else {
            echo "Error converting audio{$index} to MP4.\n";
        }
    }
    exit;
});

// Get default source and sink
[$default_source, $default_sink] = get_default_devices();

if (!$default_source || !$default_sink) {
    echo "Unable to find default source or sink.\n";
    exit(1);
}

$sinks = [$default_source, $default_sink . ".monitor"];

$handles = [];
$rawFiles = [];

// Set up the commands to capture audio from PulseAudio sinks
foreach ($sinks as $index => $sink) {
    $command = "parec -d {$sink} --format=s16le --rate=44100 --channels=2";

    // Open processes to run the commands
    $handles[$index] = popen($command, 'r');

    if ($handles[$index]) {
        // Open raw files to save the recorded audio
        $rawFiles[$index] = fopen("recorded_audio{$index}.raw", 'w');

        if (!$rawFiles[$index]) {
            echo "Unable to open raw file for writing: recorded_audio{$index}.raw\n";
        }
    } else {
        echo "Unable to execute parec command for sink: {$sink}\n";
    }
}

// Function to process each handle
function process_handle($handle, $rawFile): void {
    while (!feof($handle)) {
        $buffer = fread($handle, 8192);
        fwrite($rawFile, $buffer);
    }
    fclose($rawFile);
    pclose($handle);
}

// Fork processes to handle each recording
foreach ($handles as $index => $handle) {
    $pid = pcntl_fork();

    if ($pid == -1) {
        die('Could not fork');
    } elseif ($pid) {
        // Parent process continues
    } else {
        // Child process handles the recording
        process_handle($handle, $rawFiles[$index]);
        exit(0); // Child process exit
    }
}

echo "Recording started. Press Ctrl+C to stop.\n";

// Keep the script running to handle signal interruption
while (true) {
    sleep(1);
}

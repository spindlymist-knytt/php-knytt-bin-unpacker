<?php
require_once "knytt_bin.php";
require_once "SimpleReader.php";

ini_set("log_errors", 1);
ini_set("error_log", "error.log");

////////////////////////////////////////////////////////////////////////////////////////////////////
// Benchmarks
////////////////////////////////////////////////////////////////////////////////////////////////////

try {
    if (is_dir("output")) {
        rmdir_recursive("output");
    }
    
    $knytt_bins = list_dir("input");
    $bin_count = count($knytt_bins);

    $initial_mem_usage = peak_mem_usage_MiB();
    echo "Starting memory usage: {$initial_mem_usage} MiB\n\n";

    benchmark("List all files in one large level", function () {
        $file_name = "Lucinda - Iasthai v2.knytt.bin";
        $reader = new knytt_bin\SimpleReader("input/{$file_name}");
        $files = knytt_bin\list_all_files($reader);
    });

    benchmark("List all files in {$bin_count} levels", function () use ($knytt_bins) {
        foreach ($knytt_bins as $file_name) {
            $reader = new knytt_bin\SimpleReader("input/{$file_name}");
            $files = knytt_bin\list_all_files($reader);
        }
    });

    benchmark("Find Icon.png and World.ini in one large level", function () {
        $file_name = "Lucinda - Iasthai v2.knytt.bin";
        $reader = new knytt_bin\SimpleReader("input/{$file_name}");
        $files = knytt_bin\find_files($reader, ["Icon.png", "World.ini"]);
    });

    benchmark("Find Icon.png and World.ini in {$bin_count} levels", function () use ($knytt_bins) {
        foreach ($knytt_bins as $file_name) {
            $reader = new knytt_bin\SimpleReader("input/{$file_name}");
            $files = knytt_bin\find_files($reader, ["Icon.png", "World.ini"]);
        }
    });

    benchmark("Extract Icon.png and World.ini from one large level", function ($number) {
        $file_name = "Lucinda - Iasthai v2.knytt.bin";
        $reader = new knytt_bin\SimpleReader("input/{$file_name}");
        $files = knytt_bin\extract_files(
            $reader,
            ["Icon.png", "World.ini"],
            "./output/{$number}/{$file_name}"
        );
    });

    benchmark("Extract Icon.png and World.ini from {$bin_count} levels", function ($number) use ($knytt_bins) {
        foreach ($knytt_bins as $file_name) {
            $reader = new knytt_bin\SimpleReader("input/{$file_name}");
            $files = knytt_bin\extract_files(
                $reader,
                ["Icon.png", "World.ini"],
                "./output/{$number}/{$file_name}"
            );
        }
    });

    benchmark("Extract all files from one large level", function ($number) {
        $file_name = "Lucinda - Iasthai v2.knytt.bin";
        $reader = new knytt_bin\SimpleReader("input/{$file_name}");
        $files = knytt_bin\extract_all_files($reader, "./output/{$number}/{$file_name}");
    });

    benchmark("Extract all files from {$bin_count} levels", function ($number) use ($knytt_bins) {
        foreach ($knytt_bins as $file_name) {
            $reader = new knytt_bin\SimpleReader("input/{$file_name}");
            $files = knytt_bin\extract_all_files($reader, "./output/{$number}/{$file_name}");
        }
    });
}
catch(knytt_bin\KnyttBinException $e) {
    echo "KnyttBinException: " . $e->getMessage();
}
catch(knytt_bin\ReaderException $e) {
    echo "ReaderException: " . $e->getMessage();
}
catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}

flush();

////////////////////////////////////////////////////////////////////////////////////////////////////
// Helper functions
////////////////////////////////////////////////////////////////////////////////////////////////////

function print_header(knytt_bin\Header $header): void {
    printf("%-32s %16d %16d\n", $header->path, $header->size, $header->offset);
}

function benchmark(string $name, callable $func) {
    static $benchmark_number = -1;
    $benchmark_number++;

    echo "================================================================\n";
    echo "{$benchmark_number} {$name}\n";
    echo "================================================================\n";

    $did_reset_mem_usage = false;
    if (function_exists("memory_reset_peak_usage")) {
        memory_reset_peak_usage();
        $did_reset_mem_usage = true;
    }

    $start = hrtime(true);
    $result = call_user_func($func, $benchmark_number);
    $end = hrtime(true);

    $peak_usage = peak_mem_usage_MiB();
    $elapsed = round(($end - $start) / 1.0e6, 3);

    echo "Finished in {$elapsed} ms\n";
    echo "Peak memory usage: {$peak_usage} MiB";
    if (!$did_reset_mem_usage) {
        echo " [since this script started]";
    }
    echo "\n\n";

    return $result;
}

function list_dir(string $dir): array {
    return array_diff(scandir($dir), ["..", "."]);
}

function rmdir_recursive(string $dir): bool {
    foreach (list_dir($dir) as $file) {
        $path = "{$dir}/{$file}";

        if (is_link($path)) {
            return false;
        }
        else if (is_dir($path)) {
            if (!rmdir_recursive($path)) {
                return false;
            }
        }
        else {
            if (!unlink($path)) {
                return false;
            }
        }
    }

    return rmdir($dir);
}

function peak_mem_usage_MiB(int $precision = 2): float {
    return round(memory_get_peak_usage() / (1024.0 * 1024.0), $precision);
}

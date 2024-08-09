<?php namespace knytt_bin;

use Exception;

class KnyttBinException extends Exception { }
class ReaderException extends Exception { }

class Header {
    public string $path;
    public int $size;
    public int $offset;

    public function __construct($path, $size, $offset) {
        $this->path = $path;
        $this->size = $size;
        $this->offset = $offset;
    }
}

interface IReader {
    public function read_until(string $terminator): ?string;
    public function read(int $n_bytes): string;
    public function skip(int $n_bytes): void;
    public function tell(): int;
    public function copy_to_file(string $path, int $n_bytes, int $buffer_size): int;
}

/**
 * Parses and validates .knytt.bin header from a reader.
 * 
 * @param IReader $reader A reader pointing to the first byte of a header.
 * @param bool $force_unix_separator (optional) If true, paths will be converted to Unix-style separators.
 *     Defaults to true.
 *
 * @throws KnyttBinException If the header is invalid or the path is forbidden. A path is forbidden if
 *     any component is .. or if it is absolute.
 *
 * @returns ?Header The header that was parsed, or null if there are no bytes left in the reader.
 */
function parse_header(IReader $reader, bool $force_unix_separator=true): ?Header {
    // Check signature. Should always be NF
    
    $signature = $reader->read(2);
    
    if(strlen($signature) === 0) { // EOF, no more entries
        return null;
    }
    
    if ($signature !== "NF") {
        throw new KnyttBinException("Corrupted header: invalid signature");
    }

    // Read file path (null-terminated string)
    
    $path = $reader->read_until("\0");
    
    if ($path === null) {
        throw new KnyttBinException("Corrupted header: file path never terminated");
    }
    
    if (strlen($path) === 0) {
        throw new KnyttBinException("Corrupted header: zero-length file path");
    }

    // Check for forbidden paths
    
    $unix_path = str_replace("\\", "/", $path);
    if ($force_unix_separator) {
        $path = $unix_path;
    }

    if ($unix_path[0] === "/") {
        throw new KnyttBinException("Unsafe file path: absolute paths are forbidden");
    }
    
    foreach (explode("/", $unix_path) as $part) {
        if ($part === "..") {
            throw new KnyttBinException("Unsafe file path: '..' is forbidden");
        }
    }

    // Read file size (32-bit little-endian integer)
    
    $file_size = $reader->read(4);
    if (strlen($file_size) !== 4) {
        throw new KnyttBinException("Corrupted header: reached EOF while reading file size");
    }    
    $file_size = unpack("Vsize", $file_size);
    if ($file_size === false) {
        throw new KnyttBinException("Corrupted header: failed to unpack file size");
    }
    $file_size = $file_size["size"];

    return new Header($path, $file_size, $reader->tell());
}

/**
 * Parses all of the file headers in a .knytt.bin file.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 *
 * @returns array<string, Header> A dictionary of file paths to headers.
 */
function list_all_files(IReader $reader): array {
    return map_all_files(
        $reader,
        function ($path, $header, $reader) {
            return $header;
        }
    );
}

/**
 * Reads and parses headers for specified paths in a .knytt.bin file.
 *
 * @param IReader $reader A reader pointing to the start of the file. The headers and file contents will
 *     be consumed for each file up to and including the last one found (the reader will point to the
 *     first byte of the next header). If any file is not found, the entire reader will be consumed.
 * @param array<string> $paths The list of paths to search for.
 * @param bool $case_sensitive (optional) Whether paths should match in case. Defaults to false.
 *
 * @returns array<string, Header> A dictionary of file paths to headers. Excludes paths that are not found.
 */
function find_files(
    IReader $reader,
    array $paths,
    bool $case_sensitive = false
): array {
    return map_files(
        $reader,
        $paths,
        __make_comp_func($case_sensitive),
        function ($path, $header, $reader) {
            return $header;
        }
    );
}

/**
 * Extracts all files from a .knytt.bin file into the given directory.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param string $output_dir (optional) The directory to extract the files to. The directory will be
 *     created if it does not exist. Defaults to the current working directory.
 * @param ?callable(string):string $map_path_func (optional) A function that maps each path to a new one.
 *     This could be used to add the level's name as prefix or normalize case, for example. The mapped
 *     mapped path is relative to $output_dir.
 *
 * @return array<string, Header> A dictionary of file paths to sizes in bytes.
 */
function extract_all_files(
    IReader $reader,
    string $output_dir = ".",
    ?callable $map_path_func = null
): array {
    return map_all_files(
        $reader,
        __make_extract_func($output_dir, $map_path_func)
    );
}

/**
 * Extracts specified paths from a .knytt.bin file into the given directory.
 *
 * @param IReader $reader A reader pointing to the start of the file. The headers and file contents will
 *     be consumed for each file up to and including the last one found (the reader will point to the
 *     first byte of the next header). If any file is not found, the entire reader will be consumed.
 * @param array<string> $paths The list of paths to extract.
 * @param string $output_dir (optional) The directory to extract the files to. The directory will be
 *     created if it does not exist. Defaults to the current working directory.
 * @param bool $case_sensitive (optional) Whether paths should match in case. Defaults to false.
 * @param ?callable(string):string $map_path_func (optional) A function that maps each path to a new one.
 *     This could be used to add the level's name as prefix or normalize case, for example. The mapped
 *     mapped path is relative to $output_dir.
 *
 * @return array<string, Header> A dictionary of file paths to sizes in bytes. Excludes paths that are
 *     not found.
 */
function extract_files(
    IReader $reader,
    array $paths,
    string $output_dir = ".",
    bool $case_sensitive = false,
    ?callable $map_path_func = null
): array {
    return map_files(
        $reader,
        $paths,
        __make_comp_func($case_sensitive),
        __make_extract_func($output_dir, $map_path_func)
    );
}

/**
 * Executes a function for each file in a .knytt.bin file and collects the results in a dictionary.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param callable(string, Header, IReader):mixed $map_func A function that will be
 *     be called for each file header. The first parameter is the path of the file. The second parameter
 *     is the entire header. The third parameter is a reader pointing to the first byte of the file's
 *     contents. The function may consume zero or more bytes, but must not consume more bytes than the
 *     header's size. The return value of the function will be added to the dictionary under the file's
 *     path.
 *
 * @return array<string, mixed> A dictionary of file paths to $map_func return values.
 */
function map_all_files(IReader $reader, callable $map_func): array {
    parse_header($reader); // Skip first header

    $results = [];
    $header = parse_header($reader);

    while ($header !== null) {
        $results[$header->path] = call_user_func($map_func, $header->path, $header, $reader);

        $n_bytes_consumed = $reader->tell() - $header->offset;
        if ($n_bytes_consumed > $header->size) {
            throw new KnyttBinException("Map function consumed more bytes than the file contained");
        }
        
        $reader->skip($header->size - $n_bytes_consumed);
        $header = parse_header($reader);
    }

    return $results;
}

/**
 * Executes a function for specified paths in a .knytt.bin file and collects the results in a dictionary.
 *
 * @param IReader $reader A reader pointing to the start of the file. The headers and file contents will
 *     be consumed for each file up to and including the last one found (the reader will point to the
 *     first byte of the next header). If any file is not found, the entire reader will be consumed.
 * @param array<string> $paths The list of paths to map.
 * @param callable(string, string):bool $comp_func A function to compare paths. The first parameter is
 *     the entry in $paths. The second parameter is the . The function should return true if the paths
 *     are the same. This enables case-insensitive comparisons.
 * @param callable(string, Header, IReader):mixed $map_func A function that will be
 *     called for each file header. The first parameter is the path of the file as specified in $paths.
 *     The second parameter is the entire header. The third parameter is a reader pointing to the first
 *     byte of the file's contents. The function may consume zero or more bytes, but must not consume
 *     more bytes than the header's size. The return value of the function will be added to the
 *     dictionary under the file's path as specified in $paths.
 *
 * @return array<string, mixed> A dictionary of file paths to $map_func return values. The keys will be
 *     those specified in $paths, not those in the .knytt.bin file. Excludes paths that are not found.
 */
function map_files(
    IReader $reader,
    array $paths,
    callable $comp_func,
    callable $map_func
): array {
    parse_header($reader); // Skip first header

    $results = [];
    $n_remaining = count($paths);
    $header = parse_header($reader);
    
    while ($header !== null && $n_remaining > 0) {
        [$key, $path] = __match_first($header->path, $paths, $comp_func);

        if ($path !== null) {
            $results[$path] = call_user_func($map_func, $path, $header, $reader);

            unset($paths[$key]);
            $n_remaining--;
        }
        
        $n_bytes_consumed = $reader->tell() - $header->offset;
        if ($n_bytes_consumed > $header->size) {
            throw new KnyttBinException("Map function consumed more bytes than the file contained");
        }
        
        $reader->skip($header->size - $n_bytes_consumed);
        $header = parse_header($reader);
    }

    return $results;
}

////////////////////////////////////////////////////////////////////////////////////////////////////
// Callback helpers
////////////////////////////////////////////////////////////////////////////////////////////////////

function __make_comp_func(bool $case_sensitive): callable {
    if ($case_sensitive) {
        return function ($a, $b) {
            return $a === $b;
        };
    }
    else {
        return function ($a, $b) {
            return strcasecmp($a, $b) === 0;
        };
    }
}

function __make_extract_func(string $output_dir, ?callable $map_path_func): callable {
    if ($map_path_func === null) {
        $map_path_func = function ($path) {
            return $path;
        };
    }
    
    return function ($path, $header, $reader) use ($output_dir, $map_path_func) {
        $mapped_path = call_user_func($map_path_func, $path);
        $output_path = __join_paths($output_dir, $mapped_path);
        __create_parent_dir($output_path);

        return $reader->copy_to_file($output_path, $header->size);
    };
}

////////////////////////////////////////////////////////////////////////////////////////////////////
// Private utilities functions
////////////////////////////////////////////////////////////////////////////////////////////////////

function __match_first($needle, array $haystack, callable $comp_func): array {
    foreach ($haystack as $key => $value) {
        $is_match = call_user_func($comp_func, $needle, $value);
        if ($is_match) {
            return [$key, $value];
        }
    }

    return [null, null];
}

function __join_paths(string ...$paths): string {
    // Remove empty paths
    $paths = array_filter($paths, function ($path) {
        return strlen($path) > 0;
    });

    // Replace repeated slashes (Unix-style only)
    return preg_replace('#/{2,}#', '/', join('/', $paths));
}

function __create_parent_dir(string $path): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

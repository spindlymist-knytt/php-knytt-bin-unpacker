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
    public function read_until(string $terminator, int $max_len): ?string;
    public function read(int $n_bytes): string;
    public function skip(int $n_bytes): void;
    public function tell(): int;
    public function copy_to_file(string $path, int $n_bytes, int $buffer_size): int;
}

class ParseOptions {
    /**
     * @var array<string> List of file extensions that are permitted. Defaults to ini, bin, ogg, png, txt, html,
     *     lua, bkup, and temp.
     */
    public array $allowed_extensions;

    /**
     * @var ?string If non-null, paths will be converted to the specified character encoding. The encoding
     *     must be supported by `iconv`. Defaults to `null`.
     */
    public ?string $convert_to_encoding;

    /**
     * @var bool If `true`, a dot (period)
     */
    public bool $reject_dot_in_top_level_dir;

    /**
     * @var bool If `true`, paths will be converted to Unix-style separators (forward slash). Defaults to `true`.
     */
    public bool $force_unix_separator;

    /**
     * @var int The maximum length in bytes allowed for paths. This prevents reading an unbounded amount
     *     of data into memory if the file is corrupt or constructed with malicious intent. Defaults to 256.
     */
    public int $max_path_len;

    public function __construct(
        ?array $allowed_extensions = null,
        ?string $convert_to_encoding = null,
        bool $reject_dot_in_top_level_dir = false,
        bool $force_unix_separator = true,
        int $max_path_len = 256,
    ) {
        if ($allowed_extensions === null) {
            $allowed_extensions = ["ini", "bin", "ogg", "png", "txt", "html", "lua", "bkup", "temp"];
        }

        $this->allowed_extensions = $allowed_extensions;
        $this->convert_to_encoding = $convert_to_encoding;
        $this->reject_dot_in_top_level_dir = $reject_dot_in_top_level_dir;
        $this->force_unix_separator = $force_unix_separator;
        $this->max_path_len = $max_path_len;
    }
}

/**
 * Parses and validates .knytt.bin header from a reader.
 * 
 * @param IReader $reader A reader pointing to the first byte of a header.
 * @param ParseOptions $options Configures the behavior of the parser.
 * @param bool $is_first_header (optional) Set this to `true` when parsing the first header in the file, which
 *     describes the top-level directory. Defaults to `false`.
 *
 * @throws KnyttBinException If the header is invalid or the path is forbidden. A path is forbidden if:
 * 
 *     - it ends with a slash
 *     - it is absolute
 *     - any component is `.` or `..`
 *     - its extension is not allowed
 *     
 *     For the first header only, the "extension" is ignored and it may not contain a slash.
 *
 * @return ?Header The header that was parsed, or null if there are no bytes left in the reader.
 */
function parse_header(IReader $reader, ParseOptions $options, bool $is_first_header = false): ?Header {
    // Check signature. Should always be NF
    
    $signature = $reader->read(2);
    
    if(strlen($signature) === 0) { // EOF, no more entries
        return null;
    }
    
    if ($signature !== "NF") {
        throw new KnyttBinException("Corrupted header: invalid signature");
    }

    // Read file path (null-terminated string)
    
    $path = $reader->read_until("\0", $options->max_path_len);
    
    if ($path === null) {
        throw new KnyttBinException("Corrupted header: file path never terminated");
    }
    
    if (strlen($path) === 0) {
        throw new KnyttBinException("Corrupted header: zero-length file path");
    }

    // Check for forbidden paths
    
    $unix_path = str_replace("\\", "/", $path);
    if ($options->force_unix_separator) {
        $path = $unix_path;
    }

    if (substr($unix_path, -1) === "/") {
        throw new KnyttBinException("Invalid file path: ends with slash");
    }

    if (__is_absolute_path($unix_path)) {
        throw new KnyttBinException("Unsafe file path: absolute paths are forbidden");
    }

    foreach (explode("/", $unix_path) as $part) {
        if ($part == "." || $part === "..") {
            throw new KnyttBinException("Unsafe file path: `.` and `..` are forbidden");
        }
    }
    
    if ($is_first_header) {
        if (strpos($unix_path, "/") !== false) {
            throw new KnyttBinException("Invalid file path: slash in top-level directory");
        }
        if ($options->reject_dot_in_top_level_dir && strpos($unix_path, ".") !== false) {
            throw new KnyttBinException("Invalid file path: dot in top-level directory");
        }
    }
    else {
        if (!__has_file_extension($unix_path, $options->allowed_extensions)) {
            throw new KnyttBinException("Invalid file path: extension is not allowed");
        }
    }

    // Convert encoding

    if ($options->convert_to_encoding !== null) {
        // Technically, we may want to guess the encoding here. See e.g. "Deni - Adventure(Part 2).knytt.bin"
        // which has a file in the Intro directory that was probably originally WINDOWS-1251 (Cyrillic).
        // However, this will be correct for the vast majority of levels. I'd rather have the caller disable
        // conversion and handle it on their own if their use case demands it.
        $path = iconv("WINDOWS-1252", $options->convert_to_encoding, $path);
        if ($path === false) {
            throw new KnyttBinException("Failed to convert path to target encoding (is it supported by iconv?)");
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
 * Executes a function for each file header in a .knytt.bin file and collects the results in a dictionary.
 * 
 * For each header, the value returned by `$map_func` is stored under the key returned by `$key_func`.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param callable(Header): string|int|null $key_func A function that maps each file header to a key. If the function
 *     return `null`, the entry will be skipped. Thus, `$key_func` also serves as a filter.
 * @param callable(string|int, Header, IReader): mixed $map_func A function that maps each file header to some value.
 *     
 *     The first parameter is the key returned by `$key_func`. The second parameter is the file header. The third
 *     parameter is a reader pointing to the first byte of the file's contents. The function may consume zero or
 *     more bytes, but must not consume more bytes than the header's size.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return array<string, mixed> A dictionary that maps `$key_func` return values to `$map_func` return values.
 */
function map_all_entries(
    IReader $reader,
    callable $key_func,
    callable $map_func,
    ?ParseOptions $options = null
): array {
    if ($options === null) {
        $options = new ParseOptions();
    }

    // Skip first header
    parse_header($reader, $options, true);

    $results = [];
    $header = parse_header($reader, $options);

    while ($header !== null) {
        $key = call_user_func($key_func, $header);

        if ($key !== null) {
            $results[$key] = call_user_func($map_func, $key, $header, $reader);
        }

        $n_bytes_consumed = $reader->tell() - $header->offset;
        if ($n_bytes_consumed > $header->size) {
            throw new KnyttBinException("Map function consumed more bytes than the file contained");
        }
        
        $reader->skip($header->size - $n_bytes_consumed);
        $header = parse_header($reader, $options);
    }

    return $results;
}

/**
 * Parses all of the headers in a .knytt.bin file.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return array<string, Header> A dictionary that maps file paths to headers.
 */
function list_all_files(IReader $reader, ?ParseOptions $options = null): array {
    return map_all_entries(
        $reader,
        function ($header) {
            return $header->path;
        },
        function ($key, $header, $reader) {
            return $header;
        },
        $options
    );
}

/**
 * Parses headers for the specified files in a .knytt.bin file.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param array<string> $paths The list of paths to search for.
 * @param bool $case_sensitive (optional) Whether paths should match in case. Defaults to `false`.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return array<string, Header> A dictionary that maps file paths to headers. Excludes paths that are not found.
 *     The keys will match those provided in `$paths`.
 */
function find_files(
    IReader $reader,
    array $paths,
    bool $case_sensitive = false,
    ?ParseOptions $options = null
): array {
    return map_all_entries(
        $reader,
        __make_key_func($paths, $case_sensitive),
        function ($key, $header, $reader) {
            return $header;
        },
        $options
    );
}

/**
 * Parses the header for a single file in a .knytt.bin file.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param string $path The path to search for.
 * @param bool $case_sensitive (optional) Whether paths should match in case. Defaults to `false`.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return ?Header The header for that file, or `null` if it was not found.
 */
function find_one_file(
    IReader $reader,
    string $path,
    bool $case_sensitive = false,
    ?ParseOptions $options = null
): ?Header {
    $files = find_files($reader, [$path], $case_sensitive, $options);

    if (array_key_exists($path, $files)) {
        return $files[$path];
    }
    else {
        return null;
    }
}

/**
 * Reads the contents of the specified files in a .knytt.bin file.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param array<string> $paths The list of paths to search for.
 * @param bool $case_sensitive (optional) Whether paths should match in case. Defaults to `false`.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return array<string, string> A dictionary that maps file paths to their contents. Excludes paths that are not found.
 *     The keys will match those provided in `$paths`.
 */
function read_files(
    IReader $reader,
    array $paths,
    bool $case_sensitive = false,
    ?ParseOptions $options = null
) {
    return map_all_entries(
        $reader,
        __make_key_func($paths, $case_sensitive),
        function ($key, $header, $reader) {
            return $reader->read($header->size);
        },
        $options
    );
}

/**
 * Reads the contents of a single file in a .knytt.bin file.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param string $path The path to search for.
 * @param bool $case_sensitive (optional) Whether paths should match in case. Defaults to `false`.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return ?string The contents of that file, or `null` if it was not found.
 */
function read_one_file(
    IReader $reader,
    string $path,
    bool $case_sensitive = false,
    ?ParseOptions $options = null
) {
    $files = read_files($reader, [$path], $case_sensitive, $options);

    if (array_key_exists($path, $files)) {
        return $files[$path];
    }
    else {
        return null;
    }
}

/**
 * Extracts all files from a .knytt.bin file into a given directory.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param string $output_dir (optional) The directory to extract the files to. The directory will be created if
 *     it does not exist. Defaults to the current working directory.
 * @param int $max_file_size (optional) The maximum number of bytes allowed to be extracted for a single file. A
 *     `KnyttBinException` will be raised if a file is too large. Defaults to 256 MiB.
 * @param ?callable(string): string $map_path_func (optional) A function that maps each path to a new one. This
 *     could be used to add the level name as prefix, for example. The mapped path is relative to `$output_dir`.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return array<string, Header> A dictionary that maps file paths to headers.
 */
function extract_all_files(
    IReader $reader,
    string $output_dir = ".",
    int $max_file_size = 256 * 1024 * 1024,
    ?callable $map_path_func = null,
    ?ParseOptions $options = null
): array {
    return map_all_entries(
        $reader,
        function ($header) {
            return $header->path;
        },
        __make_extract_func($output_dir, $max_file_size, $map_path_func),
        $options
    );
}

/**
 * Extracts the specified files from a .knytt.bin file into a given directory.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param array<string> $paths The list of paths to extract.
 * @param string $output_dir (optional) The directory to extract the files to. The directory will be created if
 *     it does not exist. Defaults to the current working directory.
 * @param int $max_file_size (optional) The maximum number of bytes allowed to be extracted for a single file. A
 *     `KnyttBinException` will be raised if a file is too large. Defaults to 256 MiB.
 * @param bool $case_sensitive (optional) Whether paths should match in case. Defaults to false.
 * @param ?callable(string): string $map_path_func (optional) A function that maps each path to a new one. This
 *     could be used to add the level name as prefix, for example. The mapped path is relative to `$output_dir`.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return array<string, Header> A dictionary that maps file paths to headers. Excludes paths that are not found.
 *     The keys will match those provided in `$paths`.
 */
function extract_files(
    IReader $reader,
    array $paths,
    string $output_dir = ".",
    int $max_file_size = 256 * 1024 * 1024,
    bool $case_sensitive = false,
    ?callable $map_path_func = null,
    ?ParseOptions $options = null
): array {
    return map_all_entries(
        $reader,
        __make_key_func($paths, $case_sensitive),
        __make_extract_func($output_dir, $max_file_size, $map_path_func),
        $options
    );
}

/**
 * Extracts a single file from a .knytt.bin file.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param string $path The path of the file to extract.
 * @param string $output_path The path to write the extracted file to relative to the output directory.
 * @param string $output_dir (optional) The directory to extract the files to. The directory will be created if
 *     it does not exist. Defaults to the current working directory.
 * @param bool $case_sensitive (optional) Whether paths should match in case. Defaults to `false`.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return ?Header The header for that file, or `null` if it was not found.
 */
function extract_one_file(
    IReader $reader,
    string $path,
    string $output_path,
    string $output_dir = ".",
    int $max_file_size = 256 * 1024 * 1024,
    bool $case_sensitive = false,
    ?ParseOptions $options = null
): ?Header {
    $files = extract_files(
        $reader,
        [$path],
        $output_dir,
        $max_file_size,
        $case_sensitive,
        function ($path) use ($output_path) {
            return $output_path;
        },
        $options
    );

    if (array_key_exists($path, $files)) {
        return $files[$path];
    }
    else {
        return null;
    }
}

 /**
 * Parses the headers in a .knytt.bin if it appears to contain a valid level. If it is invalid, throws
 * a `KnyttBinException`.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return array<string, Header> A dictionary of file paths to headers.
 */
function validate_level(IReader $reader, ?ParseOptions $options = null): array {
    $headers = list_all_files($reader, $options);

    $has_world_ini = false;
    $has_map_bin = false;

    foreach ($headers as $header) {
        if (!$has_map_bin && strcasecmp($header->path, "Map.bin") === 0) {
            $has_map_bin = true;
        }
        if (!$has_world_ini && strcasecmp($header->path, "World.ini") === 0) {
            $has_world_ini = true;
        }
    }

    if(!$has_world_ini) {
        throw new KnyttBinException("Invalid level: World.ini is missing");
    }

    if (!$has_map_bin) {
        throw new KnyttBinException("Invalid level: Map.bin is missing");
    }

    return $headers;
}

 /**
 * Returns `true` if a .knytt.bin appears to contain a valid level.
 *
 * @param IReader $reader A reader pointing to the start of the file. The entire reader will be consumed.
 * @param ParseOptions $options (optional) Configures the behavior of the parser. If `null`, the defaults will be used.
 *
 * @return bool Whether the .knytt.bin contains a valid level.
 */
function is_valid_level(IReader $reader, ?ParseOptions $options = null): bool {
    try {
        validate_level($reader, $options);
        return true;
    }
    catch (KnyttBinException $e) {
        return false;
    }
}

/**
 * Returns `true` if the path should be compatible with KS.
 * 
 * KS cannot install a .knytt.bin if its path cannot be losslessly transcoded to Windows-1252. This function may not
 * be perfectly accurate.
 */
function is_ks_compatible_path(string $path, string $encoding): bool {
    return iconv($encoding, "WINDOWS-1252", $path) !== false;
}

////////////////////////////////////////////////////////////////////////////////////////////////////
// Callback helpers
////////////////////////////////////////////////////////////////////////////////////////////////////

/**
 * Makes a function that determines if a given path is present in `$paths` (with or without case sensitivity). If
 * it is, it returns the matching element of `$paths`. Otherwise, it returns `null`.
 */
function __make_key_func(array $paths, bool $case_sensitive): callable {
    if ($case_sensitive) {
        return function ($header) use ($paths) {
            $i = array_search($header->path, $paths);
            if ($i === null) {
                return null;
            }
            else {
                return $paths[$i];
            }
        };
    }
    else {
        return function ($header) use ($paths) {
            foreach ($paths as $path) {
                if (strcasecmp($path, $header->path) === 0) {
                    return $path;
                }
            }
            return null;
        };
    }
}

/**
 * Makes a function that extracts .knytt.bin entries to `$output_dir`, possibly mapping the relative path
 * to a different one via `$map_path_func`. The callback will throw a `KnyttBinException` if the file
 * is larger than `$max_file_size`.
 */
function __make_extract_func(string $output_dir, int $max_file_size, ?callable $map_path_func): callable {
    if ($map_path_func === null) {
        $map_path_func = function ($path) {
            return $path;
        };
    }
    
    return function ($path, $header, $reader) use ($output_dir, $max_file_size, $map_path_func) {
        if ($header->size > $max_file_size) {
            throw new KnyttBinException("File was too large");
        }

        $mapped_path = call_user_func($map_path_func, $path);
        $output_path = __join_paths($output_dir, $mapped_path);
        
        __create_parent_dir($output_path);
        $reader->copy_to_file($output_path, $header->size);

        return $header;
    };
}

////////////////////////////////////////////////////////////////////////////////////////////////////
// Private utilities functions
////////////////////////////////////////////////////////////////////////////////////////////////////

/**
 * Creates the directory that contains `$path` if it doesn't already exist.
 */
function __create_parent_dir(string $path): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

/**
 * Joins two or more paths together without repeated slashes. IMPORTANT: Only works with `/` as the path separator.
 */
function __join_paths(string ...$paths): string {
    // Remove empty paths
    $paths = array_filter($paths, function ($path) {
        return strlen($path) > 0;
    });

    // Replace repeated slashes (Unix-style only)
    return preg_replace('#/{2,}#', '/', join('/', $paths));
}

/**
 * Returns `true` if a path is absolute. IMPORTANT: Only works with `/` as the path separator.
 */
function __is_absolute_path(string $path): bool {
    return (
        substr($path, 0, 1) === "/"     // Unix-style absolute path, e.g. /path/to/file
        || substr($path, 1, 2) === ":/" // Windows absolute path, e.g. C:/path/to/file
    );
}

/**
 * Returns `true` extension if `$path` has any of the given extensions. The comparison is case insensitive.
 */
function __has_file_extension(string $path, array $extensions): string {
    $dot_idx = strrpos($path, ".");
    if ($dot_idx === false) {
        return false;
    }

    $path_ext = substr($path, $dot_idx + 1);
    foreach ($extensions as $ext) {
        if (strcasecmp($ext, $path_ext) === 0) {
            return true;
        }
    }

    return false;
}

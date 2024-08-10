# .knytt.bin Unpacker for PHP

This is a PHP library for parsing and unpacking .knytt.bin files in a memory-efficient way. A .knytt.bin file
is an uncompressed directory archive used to distribute levels for the indie game Knytt Stories (2007).

## Structure

- [`knytt_bin.php`](knytt_bin.php) contains the code for parsing and unpacking.
- [`SimpleReader.php`](SimpleReader.php) is a utility class for file I/O.
- [`index.php`](index.php) contains a few basic benchmarks. You must place .knytt.bin files in the `input` directory
  for them to work. Note that it looks for [`Lucinda - Iasthai v2.knytt.bin`](https://knyttlevels.com/levels/Lucinda%20-%20Iasthai%20v2.knytt.bin)
  in that directory to test performance on a single large input.

## Examples

### List all files

```php
$reader = new knytt_bin\SimpleReader("Nifflas - The Machine.knytt.bin");
$files = knytt_bin\list_all_files($reader);
print_r($files);

// Array
// (
//     [Bonus/Scene1.png] => knytt_bin\Header Object
//         (
//             [path] => Bonus/Scene1.png
//             [size] => 200911
//             [offset] => 51
//         )
//
// ... and so on ...
//
//     [World.ini] => knytt_bin\Header Object
//         (
//             [path] => World.ini
//             [size] => 1883
//             [offset] => 1258450
//         )
//
// )
```

### Get header for a single file

```php
$reader = new knytt_bin\SimpleReader("Nifflas - The Machine.knytt.bin");
$header = knytt_bin\find_one_file($reader, "Icon.png");

if ($header !== null) {
    echo "Found Icon.png " . $header->offset . " bytes from the start of the file.";
}
else {
    echo "Icon.png is missing!";
}
```

### Get headers for specific files

```php
$reader = new knytt_bin\SimpleReader("Nifflas - The Machine.knytt.bin");
$files = knytt_bin\find_files($reader, ["Icon.png", "World.ini"]);
print_r($files);

// Array
// (
//     [Icon.png] => knytt_bin\Header Object
//         (
//             [path] => Icon.png
//             [size] => 459
//             [offset] => 1095236
//         )
//
//     [World.ini] => knytt_bin\Header Object
//         (
//             [path] => World.ini
//             [size] => 1883
//             [offset] => 1258450
//         )
//
// )
```

### Extract all files

```php
$reader = new knytt_bin\SimpleReader("Nifflas - The Machine.knytt.bin");
$files = knytt_bin\extract_all_files($reader, "path/to/Nifflas - The Machine");
```

### Extract one file

```php
$reader = new knytt_bin\SimpleReader("Nifflas - The Machine.knytt.bin");
$header = knytt_bin\extract_one_file($reader, "Icon.png", "Nifflas - The Machine.png", "path/to/files");

if ($header !== null) {
    echo "Extracted Icon.png and it was " . $header->size . " bytes.";
}
else {
    echo "Icon.png is missing!";
}
```

### Extract specific files

```php
$reader = new knytt_bin\SimpleReader("Nifflas - The Machine.knytt.bin");
$files = knytt_bin\extract_files(
    $reader,
    ["Icon.png", "World.ini"],
    "path/to/Nifflas - The Machine"
);

// Check whether a file was found. The array key will be the one provided when
// calling extract_files even if the matched file is capitalized differently.
if (array_key_exists("Icon.png", $files)) {
    echo "Extracted Icon.png and it was " . $files["Icon.png"]->size . " bytes.";
}
else {
    echo "Icon.png is missing!";
}
```

### Extract files with extra options

```php
$level_name = "Nifflas - The Machine";
$reader = new knytt_bin\SimpleReader("{$level_name}.knytt.bin");

// Set a maximum file size in bytes (default: 256 MiB)
$max_file_size = 2 * 1024 * 1024;

// Turn case sensitivity on or off (default: off)
$case_sensitive = false;

// Takes a path that was found and returns a path to write the file to.
// The returned path is relative to the output directory.
// $path will be the one provided when calling extract_files even if the
// matched file is capitalized differently.
$map_path_func = function ($path) use ($level_name) {
    if ($path == "Icon.png") {
        return "{$level_name}.png";
    }
    else {
        return "{$level_name}.ini";
    }
};

$files = knytt_bin\extract_files(
    $reader,
    ["Icon.png", "World.ini"],
    "path/to/files",
    $max_file_size,
    $case_sensitive,
    $map_path_func
);
```

## Configuration

The parser has a few options that can be overridden with a `knytt_bin\ParseOptions` object. For example:

```php
$options = new knytt_bin\ParseOptions();
$options->convert_to_encoding = "UTF-8"; // Convert paths to UTF-8
$options->force_unix_paths = false;      // Don't replace \ in paths with /
$options->max_path_len = 512;            // Allow paths to be up to 512 bytes
$files = knytt_bin\list_all_files($reader, $options);
```

Every public function in `knytt_bin` accepts an optional `$options` parameter. See the documentation for
`ParseOptions` for more details.

## Security

There are potential security risks associated with unpacking user-provided .knytt.bin files. This library has the
following checks in place to mitigate risk:

- Paths are limited to 256 bytes in length by default. This prevents reading an unbounded amount of data into memory
  if the file is corrupt or constructed with malicious intent.
- Paths containing `..` as a component are rejected and will raise an exception.
- Absolute paths such as `/path/to/file` and `C:\path\to\file` are rejected and will raise an exception.
- The `extract_*` functions accept a `$max_file_size` parameter that limits the number of bytes that may be extracted
  for a single file. An exception will be raised if that limit would be exceeded. The default is 256 MiB.

## Benchmarks

This isn't particularly scientific, but here are the results of running the benchmarks on the entire library
of [knyttlevels.com](https://knyttlevels.com) (as of 2024-08-07) on a moderately powerful gaming PC.

```
Starting memory usage: 0.79 MiB

================================================================
0 List all files in one large level
================================================================
Finished in 1.987 ms
Peak memory usage: 0.77 MiB

================================================================
1 List all files in 2932 levels
================================================================
Finished in 1174.181 ms
Peak memory usage: 0.89 MiB

================================================================
2 Find Icon.png and World.ini in one large level
================================================================
Finished in 1.975 ms
Peak memory usage: 0.73 MiB

================================================================
3 Find Icon.png and World.ini in 2932 levels
================================================================
Finished in 903.704 ms
Peak memory usage: 0.74 MiB

================================================================
4 Extract Icon.png and World.ini from one large level
================================================================
Finished in 6.915 ms
Peak memory usage: 0.73 MiB

================================================================
5 Extract Icon.png and World.ini from 2932 levels
================================================================
Finished in 10743.547 ms
Peak memory usage: 0.74 MiB

================================================================
6 Extract all files from one large level
================================================================
Finished in 310.08 ms
Peak memory usage: 0.76 MiB

================================================================
7 Extract all files from 2932 levels
================================================================
Finished in 90691.644 ms
Peak memory usage: 0.82 MiB
```
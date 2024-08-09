# .knytt.bin Unpacker for PHP

This is a PHP script that can parse and unpack .knytt.bin files, the format used to distribute levels for the 2007
indie game Knytt Stories.

- [`knytt_bin.php`](knytt_bin.php) contains the code for parsing and unpacking.
- [`SimpleReader.php`](SimpleReader.php) is a utility class for file I/O.
- [`index.php`](index.php) contains a few benchmarks. You must place .knytt.bin files in the `input` directory
  for them to work. Note that it looks for [`Lucinda - Iasthai v2.knytt.bin`](https://knyttlevels.com/levels/Lucinda%20-%20Iasthai%20v2.knytt.bin)
  in that directory to test performance on a single large input.

<?php namespace knytt_bin;

class SimpleReader implements IReader {
    private $file = null;
    
    public function __construct(string $path, int $buffer_size = -1) {
        $this->file = $this->open_file($path, "rb", $buffer_size);
    }

    public function __destruct() {
        $this->close_file($this->file);
    }

    private function open_file(string $path, string $mode, int $read_buffer_size=-1, int $write_buffer_size=-1) {
        $file = fopen($path, $mode);
        
        if ($file === false) {
            throw new ReaderException(sprintf("fopen failed with path `%s`", $path));
        }

        if (
            $read_buffer_size >= 0
            && stream_set_read_buffer($file, $read_buffer_size) !== 0
        ) {
            error_log(sprintf("Warning: failed to set read buffer size (`%s`:%d)", __FILE__, __LINE__), 0);
        }

        if (
            $write_buffer_size >= 0
            && stream_set_write_buffer($file, $write_buffer_size) !== 0
        ) {
            error_log(sprintf("Warning: failed to set write buffer size (`%s`:%d)", __FILE__, __LINE__), 0);
        }
        
        return $file;
    }

    private function close_file($file) {
        if (fclose($file) === false) {
            error_log(sprintf("Warning: failed to close file (`%s`:%d)", __FILE__, __LINE__), 0);
        }
    }

    public function read_until(string $terminator, int $max_len): ?string {
        $result = "";
        
        $bytes_read = 0;
        $next_byte = fgetc($this->file);
        if ($next_byte === false) {
            return null;
        }

        while ($next_byte !== $terminator) {
            $bytes_read += 1;
            if ($bytes_read > $max_len) {
                return null;
            }

            $result .= $next_byte;
            
            $next_byte = fgetc($this->file);
            if ($next_byte === false) {
                return null;
            }
        }

        return $result;
    }

    public function read(int $n_bytes): string {
        $result = fread($this->file, $n_bytes);

        if ($result === false) {
            throw new ReaderException("fread failed");
        }

        return $result;
    }

    public function skip(int $n_bytes): void {
        if (fseek($this->file, $n_bytes, SEEK_CUR) !== 0) {
            throw new ReaderException("fseek failed");
        }
    }

    public function tell(): int {
        $result = ftell($this->file);

        if ($result === false) {
            throw new ReaderException("ftell failed");
        }

        return $result;
    }

    public function copy_to_file(string $path, int $n_bytes, int $buffer_size = -1): int {
        $out_file = $this->open_file($path, "wb", -1, $buffer_size);
        $result = stream_copy_to_stream($this->file, $out_file, $n_bytes);
        $this->close_file($out_file);

        if ($result === false) {
            throw new ReaderException("stream_copy_to_stream failed");
        }

        return $result;
    }
}

<?php

namespace Drupal\feeds\Component;

/**
 * Parses an RFC 4180 style CSV file.
 *
 * @see http://tools.ietf.org/html/rfc4180
 */
class CsvParser implements \Iterator {

  /**
   * The column delimiter.
   *
   * @var string
   */
  protected $delimiter = ',';

  /**
   * Whether or not the first line contains a header.
   *
   * @var bool
   */
  protected $hasHeader = FALSE;

  /**
   * The position in the file to start from.
   *
   * @var int
   */
  protected $startByte = 0;

  /**
   * The file handle to the CSV file.
   *
   * @var resource
   */
  protected $handle;

  /**
   * The current line.
   *
   * @var array|bool
   */
  protected $currentLine;

  /**
   * The number of lines read.
   *
   * @var int
   */
  protected $linesRead = 0;

  /**
   * The byte position in the file.
   *
   * @var int
   */
  protected $filePosition;

  /**
   * Constructs a CsvParser object.
   *
   * @param resource $handle
   *   An open file handle.
   */
  public function __construct($handle) {
    if (!is_resource($handle)) {
      throw new \InvalidArgumentException('$handle must be a resource.');
    }
    $this->handle = $handle;
  }

  /**
   * Creates a CsvParser object from a file path.
   *
   * @param string $filepath
   *   The file path.
   *
   * @return \Drupal\feeds\Component\CsvParser
   *   A new CsvParser object.
   */
  public static function createFromFilePath($filepath) {
    if (!is_file($filepath) || !is_readable($filepath)) {
      throw new \InvalidArgumentException('$filepath must exist and be readable.');
    }
    $handle = fopen($filepath, 'rb');

    return new static($handle);
  }

  /**
   * Creates a CsvParser object from a file path.
   *
   * @param string $filepath
   *   The file path.
   *
   * @return \Drupal\feeds\Component\CsvParser
   *   A new CsvParser object.
   */

  public static function convertToUtf8($string) {
    mb_detect_order(['UTF-8', 'WINDOWS-1251', 'ISO-8859-1', 'ISO-8859-15', 'KOI8-R', 'CP1251']);

    $encoding = mb_detect_encoding($string, mb_detect_order(), true);

    if ($encoding && $encoding !== "UTF-8") {
        $string = iconv($encoding, "UTF-8", $string);
    }

    return $string;
  }
  /**
   * Creates a CsvParser object from a string.
   *
   * @param string $string
   *   The in-memory contents of a CSV file.
   *
   * @return \Drupal\feeds\Component\CsvParser
   *   A new CsvParser object.
   */
  public static function createFromString($string) {
    $handle = fopen('php://temp', 'w+b');
    $utf8_string = self::convertToUtf8($string);
    fwrite($handle, $utf8_string);
    fseek($handle, 0);

    return new static($handle);
  }

  /**
   * Destructs a CsvParser object.
   */
  public function __destruct() {
    if (is_resource($this->handle)) {
      fclose($this->handle);
    }
  }

  /**
   * Sets the column delimiter string.
   *
   * @param string $delimiter
   *   By default, the comma (',') is used as delimiter.
   *
   * @return $this
   */
  public function setDelimiter($delimiter) {
    $this->delimiter = $delimiter;
    return $this;
  }

  /**
   * Sets whether or not the CSV file contains a header.
   *
   * @param bool $has_header
   *   (optional) Whether or the CSV file has a header. Defaults to true.
   *
   * @return $this
   */
  public function setHasHeader($has_header = TRUE) {
    $this->hasHeader = (bool) $has_header;
    return $this;
  }

  /**
   * Returns the header row.
   *
   * @return array
   *   A list of the header names.
   */
  public function getHeader() {
    $prev = ftell($this->handle);

    rewind($this->handle);
    $line = $this->readLine();
    $header = $this->parseLine(self::convertToUtf8($line));
    fseek($this->handle, $prev);

    return $header;
  }

  /**
   * Gets the byte number where the parser left off.
   *
   * This position can be used to set the start byte for the next iteration.
   *
   * @return int
   *   The byte position of where parsing ended.
   */
  public function lastLinePos() {
    return $this->filePosition;
  }

  /**
   * Sets the byte where file should be started at.
   *
   * Useful when parsing a file in batches.
   *
   * @param int $start
   *   The byte position to start parsing.
   *
   * @return $this
   */
  public function setStartByte($start) {
    $this->startByte = (int) $start;
    return $this;
  }

  /**
   * Implements \Iterator::current().
   */
  public function current(): mixed {
    return $this->currentLine;
  }

  /**
   * Implements \Iterator::key().
   */
  public function key(): mixed {
    return $this->linesRead - 1;
  }

  /**
   * Implements \Iterator::next().
   */
  public function next(): void {
    // Record the file position before reading the next line since we
    // preemptively read lines to avoid returning empty rows.
    $this->filePosition = ftell($this->handle);

    do {
      $line = $this->readLine();
      $utf8_line = self::convertToUtf8($line);

      // End of file.
      if ($utf8_line === FALSE) {
        $this->currentLine = FALSE;
        return;
      }

      // Skip empty lines that aren't wrapped in an enclosure.
    } while (!strlen(rtrim($utf8_line, "\r\n")));

    $this->currentLine = $this->parseLine($utf8_line);
    $this->linesRead++;
  }

  /**
   * Implements \Iterator::rewind().
   */
  public function rewind(): void {
    rewind($this->handle);

    if ($this->hasHeader && !$this->startByte) {
      $line = $this->readLine();
      $utf8_line = self::convertToUtf8($line);
      $this->parseLine($utf8_line);
    }
    elseif ($this->startByte) {
      fseek($this->handle, $this->startByte);
    }

    $this->linesRead = 0;
    // Preemptively advance to the next line.
    $this->next();
  }

  /**
   * Implements \Iterator::valid().
   */
  public function valid(): bool {
    return (bool) $this->currentLine;
  }

  /**
   * Returns a new line from the CSV file.
   *
   * @return string|bool
   *   Returns the next line in the file, or false if the end has been reached.
   *
   * @todo Add encoding conversion.
   */
  protected function readLine() {
    return fgets($this->handle);
  }

  /**
   * Parses a single CSV line.
   *
   * @param string $line
   *   A line from a CSV file.
   * @param bool $in_quotes
   *   Do not use. For recursion only.
   * @param string $field
   *   Do not use. For recursion only.
   * @param array $fields
   *   Do not use. For recursion only.
   *
   * @return array
   *   The list of cells in the CSV row.
   */
    protected function parseLine($line) {
        $utf8_line = self::convertToUtf8($line);
        $fields = [];
        $field = '';
        $in_quotes = false;
        $line_length = mb_strlen($utf8_line);

        for ($index = 0; $index < $line_length; ++$index) {
            $byte = mb_substr($utf8_line, $index, 1);
            $next_byte = mb_substr($utf8_line, $index + 1, 1);

            if ($byte === '"' && $field === '' && !$in_quotes) {
                $in_quotes = true;
            } elseif ($byte === '"' && $next_byte !== '"' && $in_quotes) {
                $in_quotes = false;
            } elseif ($byte === '"' && $next_byte === '"' && $in_quotes) {
                $field .= '"';
                ++$index; // Пропускаем следующий символ
            } elseif (!$in_quotes && $byte === $this->delimiter) {
                $fields[] = $field;
                $field = '';
            } elseif (!$in_quotes && $next_byte === '') {
                $fields[] = $this->trimNewline($byte, $field);
                $field = '';
            } else {
                $field .= $byte;
            }
        }

        if (!$in_quotes && $field) {
            $fields[] = $field;
        }

        return $fields;
    }

  /**
   * Removes the trailing line ending.
   *
   * This does not call trim() since we only want to remove the last line
   * ending, not all line endings.
   *
   * @param string $last_character
   *   The last character.
   * @param string $field
   *   The current field.
   *
   * @return string
   *   The field with the line ending removed.
   */
  protected function trimNewline($last_character, $field) {
    // Windows line ending.
    if ($last_character === "\n" && substr($field, -1) === "\r") {
      return substr($field, 0, -1);
    }

    // Unix or Mac line ending.
    if ($last_character === "\n" || $last_character === "\r") {
      return $field;
    }

    // Line ended without a trailing newline.
    return $field . $last_character;
  }

}

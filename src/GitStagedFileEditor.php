<?php

namespace Xwillq\GitStageEditor;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class GitStagedFileEditor
{
    protected string $path;

    /**
     * @param  string|null  $git_root
     *
     * @throws RuntimeException
     */
    public function __construct(?string $git_root = null)
    {
        if (! is_dir($git_root.'/.git') && ! is_file($git_root.'/.git')) {
            throw new RuntimeException(sprintf('Invalid git repository: %s', $git_root));
        }
        if ($git_root === null || $git_root === '') {
            /** @phpstan-ignore-next-line */
            $this->path = getcwd();
        } else {
            $this->path = $git_root;
        }
    }

    /**
     * @param  callable(resource, string): void  $callback  Callback that will receive resource and path of a file to edit
     * @param  array<string>  $file_patterns
     * @param  bool  $write
     * @param  bool  $update_working_tree
     * @return array<string>
     *
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public function execute(
        callable $callback,
        array $file_patterns = [],
        bool $write = true,
        bool $update_working_tree = true,
    ): array {
        $diff_output = $this->runCommand(['git', 'diff-index', '--cached', '--diff-filter=AM', '--no-renames', 'HEAD']);

        $updated_files = [];
        foreach (explode('\n', $diff_output) as $output_line) {
            $entry = $this->parseDiff(trim($output_line));

            // Skip symlinks
            if ($entry->dst_mode === '120000') {
                continue;
            }

            $entry_path = $this->normalizePath($entry->src_path);
            if (! $this->pathMatches($entry_path, $file_patterns)) {
                continue;
            }

            if ($this->executeOnFileInIndex($callback, $entry, $write, $update_working_tree) !== null) {
                $updated_files[] = $entry->src_path;
            }
        }

        return $updated_files;
    }

    /**
     * @param  callable(resource, string): void  $callback
     * @param  DiffEntry  $diff_entry
     * @param  bool  $write
     * @param  bool  $update_working_tree
     * @return string|null
     *
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    private function executeOnFileInIndex(
        callable $callback,
        DiffEntry $diff_entry,
        bool $write = true,
        bool $update_working_tree = true,
    ): ?string {
        $orig_hash = $diff_entry->dst_hash;
        $new_hash = $this->executeOnObject($callback, $orig_hash);

        if (! $write || $new_hash === $orig_hash) {
            return null;
        }

        if ($this->objectIsEmpty($new_hash)) {
            return null;
        }

        $this->replaceFileInIndex($diff_entry, $new_hash);

        if ($update_working_tree) {
            $this->patchWorkingFile($diff_entry->src_path, $orig_hash, $new_hash);
        }

        return $new_hash;
    }

    /**
     * @param  callable(resource, string): void  $callback
     * @param  string  $object_hash
     * @return string
     *
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    private function executeOnObject(callable $callback, string $object_hash): string
    {
        $contents = $this->runCommand(['git', 'cat-file', '-p', $object_hash]);

        $tmp_file = tmpfile();
        if ($tmp_file === false) {
            throw new RuntimeException('Can\'t create temporary file');
        }
        $tmp_file_path = stream_get_meta_data($tmp_file)['uri'];

        fwrite($tmp_file, $contents);

        $callback($tmp_file, $tmp_file_path);

        $new_hash = $this->runCommand(['git', 'hash-object', '-w', $tmp_file_path]);

        fclose($tmp_file);

        return trim($new_hash);
    }

    /**
     * @param  string  $object_hash
     * @return bool
     *
     * @throws ProcessFailedException
     */
    private function objectIsEmpty(string $object_hash): bool
    {
        return trim($this->runCommand(['git', 'cat-file', '-p', $object_hash])) == '';
    }

    /**
     * @param  DiffEntry  $diff_entry
     * @param  string  $new_object_hash
     * @return void
     *
     * @throws ProcessFailedException
     */
    private function replaceFileInIndex(DiffEntry $diff_entry, string $new_object_hash): void
    {
        $this->runCommand([
            'git', 'update-index', '--cacheinfo',
            "$diff_entry->dst_mode,$new_object_hash,$diff_entry->src_path",
        ],
        );
    }

    /**
     * @param  string  $path
     * @param  string  $orig_hash
     * @param  string  $new_hash
     * @return void
     *
     * @throws ProcessFailedException
     */
    private function patchWorkingFile(string $path, string $orig_hash, string $new_hash): void
    {
        $patch = str_replace(
            [$orig_hash, $new_hash],
            $path,
            $this->runCommand(['git', 'diff', '--color=never', $orig_hash, $new_hash]),
        );

        $this->runCommand(['git', 'apply', '-'], $patch);
    }

    /**
     * @param  string  $line
     * @return DiffEntry
     *
     * @throws RuntimeException
     */
    private function parseDiff(string $line): DiffEntry
    {
        $matched = preg_match(
            '/^:(\d+) (\d+) ([a-f0-9]+) ([a-f0-9]+) ([A-Z])(\d+)?\t([^\t]+)(?:\t([^\t]+))?$/',
            $line,
            $matches,
        );

        if ($matched === false) {
            throw new RuntimeException('Regex exception occured');
        } elseif ($matched === 0) {
            throw new RuntimeException('Git returned unexpected string format');
        }

        return new DiffEntry(
            $matches[1],
            $matches[2],
            $matches[3],
            $matches[4],
            $matches[5],
            is_int($matches[6]) ? $matches[6] : null,
            $matches[7],
            $matches[8] ?? null,
        );
    }

    /**
     * @throws RuntimeException
     */
    private function normalizePath(string $path): string
    {
        $realpath = realpath("$this->path/$path");
        if ($realpath === false) {
            throw new RuntimeException("Couln't get full path of $path");
        }

        return $realpath;
    }

    /**
     * @param  string  $path
     * @param  array<string>  $patterns
     * @return bool
     *
     * @throws RuntimeException
     */
    private function pathMatches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '/')) {
                $matched = preg_match($pattern, $path);
                if ($matched === false) {
                    throw new RuntimeException('Regex exception occured');
                }

                if ($matched === 1) {
                    return true;
                }
            } elseif (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string>  $command
     * @param  string|null  $input
     * @return string
     *
     * @throws ProcessFailedException
     */
    protected function runCommand(array $command, ?string $input = null): string
    {
        /** @throws void */
        $proc = new Process($command, $this->path);
        if ($input !== null) {
            /** @throws void */
            $proc->setInput($input);
        }

        $proc = $proc->mustRun();

        /** @throws void */
        return $proc->getOutput();
    }
}

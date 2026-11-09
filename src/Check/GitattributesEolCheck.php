<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Warns when the files a Linux container reads line by line are not pinned
 * to LF line endings in .gitattributes, and when one of them already has
 * Windows line endings (CRLF) under deploy/ or .squire/. A carriage return
 * in a shell script, an Apache or nginx .conf or a Dockerfile breaks the
 * container that reads it, and Git on Windows adds one easily. It warns,
 * never fails, so no app that passed 0.10.0 fails because of it.
 *
 * proctor/gitattributes-eol/not-pinned: for each kind the app has (a *.sh
 * file, a *.conf file, a Dockerfile or *.Dockerfile, anywhere outside the
 * skipped folders), every file of that kind must come out of .gitattributes
 * with eol=lf and text not unset. The attributes are worked out the way Git
 * does it: every .gitattributes from the app root down to the file's folder,
 * lines in order, a later line or a deeper file overriding an earlier one.
 * One warning, on .gitattributes, naming the exact lines to add.
 *
 * proctor/gitattributes-eol/crlf: a Dockerfile, *.Dockerfile or *.conf at
 * any depth under the top-level deploy/ or .squire/ with a carriage return
 * on disk. One warning per file, on the line of its first carriage return.
 * Shell scripts there are crlf-deploy-script's job, and it fails on them.
 *
 * Skipped at any depth: vendor/, node_modules/, .git/ and tests/.
 *
 * Every byte on its own line, and every line ending in the same place: the
 * proctor collects the papers face up.
 */
final class GitattributesEolCheck implements CheckInterface
{
    /** Directory names skipped at any depth. */
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', 'tests'];

    /** Top-level directories whose Dockerfiles and .conf files are read for CRLF bytes. */
    private const CRLF_DIRS = ['deploy', '.squire'];

    /**
     * The kinds of file that must be pinned to LF, in report order: the
     * pattern the message names and the .gitattributes lines that pin it.
     */
    private const KINDS = [
        'sh' => ['label' => '*.sh', 'lines' => ['*.sh text eol=lf']],
        'conf' => ['label' => '*.conf', 'lines' => ['*.conf text eol=lf']],
        'dockerfile' => ['label' => 'Dockerfile', 'lines' => ['Dockerfile text eol=lf', '*.Dockerfile text eol=lf']],
    ];

    private const RENORMALIZE = 'git add --renormalize .';

    public function id(): string
    {
        return 'gitattributes-eol';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        [$files, $attributeFiles] = $this->walk($appPath);

        $byKind = [];
        foreach ($files as $relativePath) {
            $kind = $this->kindOf(basename($relativePath));
            if (null !== $kind) {
                $byKind[$kind][] = $relativePath;
            }
        }
        if ([] === $byKind) {
            return [new Finding($this->id(), Finding::STATUS_PASS, 'No shell script, .conf file or Dockerfile to pin to LF.')];
        }

        $rules = $this->loadRules($appPath, $attributeFiles);

        $unpinned = [];
        foreach (array_keys(self::KINDS) as $kind) {
            foreach ($byKind[$kind] ?? [] as $relativePath) {
                if (!$this->pinnedToLf($relativePath, $rules)) {
                    $unpinned[$kind] = $relativePath;
                    break;
                }
            }
        }

        $findings = [];
        if ([] !== $unpinned) {
            $findings[] = $this->notPinned($unpinned, in_array('.gitattributes', $attributeFiles, true));
        }

        foreach ($files as $relativePath) {
            $segments = explode('/', $relativePath);
            if (count($segments) < 2 || !in_array($segments[0], self::CRLF_DIRS, true)) {
                continue;
            }
            $kind = $this->kindOf(end($segments));
            if ('conf' !== $kind && 'dockerfile' !== $kind) {
                continue;
            }
            $raw = file_get_contents($appPath . '/' . $relativePath);
            if (false === $raw) {
                continue;
            }
            $line = $this->lineOfFirstCarriageReturn($raw);
            if (null !== $line) {
                $findings[] = $this->crlf($relativePath, $line, $kind);
            }
        }

        if ([] === $findings) {
            $findings[] = new Finding($this->id(), Finding::STATUS_PASS, 'Shell scripts, .conf files and Dockerfiles are pinned to LF line endings.');
        }

        return $findings;
    }

    /**
     * Every file under the app (relative, forward slashes, sorted) outside
     * the skipped folders, and the subset of them named .gitattributes.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function walk(string $appPath): array
    {
        $inner = new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS);
        $outer = new RecursiveCallbackFilterIterator($inner, static function (SplFileInfo $current): bool {
            return !($current->isDir() && in_array($current->getBasename(), self::SKIP_DIRS, true));
        });
        $iterator = new RecursiveIteratorIterator($outer, RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($appPath) + 1));
            }
        }
        sort($files);

        $attributeFiles = array_values(array_filter(
            $files,
            static fn(string $path): bool => '.gitattributes' === basename($path),
        ));

        return [$files, $attributeFiles];
    }

    /**
     * The kind of file a basename is (a key of KINDS), or null.
     */
    private function kindOf(string $basename): ?string
    {
        return match (true) {
            str_ends_with($basename, '.sh') => 'sh',
            str_ends_with($basename, '.conf') => 'conf',
            'Dockerfile' === $basename, str_ends_with($basename, '.Dockerfile') => 'dockerfile',
            default => null,
        };
    }

    /**
     * The parsed lines of every .gitattributes, keyed by the folder it sits
     * in ('' for the app root): each rule is the pattern turned into a
     * regular expression, whether it matches the basename only, and the
     * line-ending attributes it sets.
     *
     * @param list<string> $attributeFiles
     *
     * @return array<string, list<array{regex: string, basename: bool, attrs: array<string, string>}>>
     */
    private function loadRules(string $appPath, array $attributeFiles): array
    {
        $rules = [];
        foreach ($attributeFiles as $relativePath) {
            $contents = file_get_contents($appPath . '/' . $relativePath);
            if (false === $contents) {
                continue;
            }
            $dir = dirname($relativePath);
            $rules['.' === $dir ? '' : $dir] = $this->parse($contents);
        }

        return $rules;
    }

    /**
     * Parses one .gitattributes file. Blank lines, # comments and [attr]
     * macro definitions are skipped; so are negative patterns (Git rejects
     * them) and patterns ending in / (they name a folder, and attributes do
     * not flow down into it).
     *
     * @return list<array{regex: string, basename: bool, attrs: array<string, string>}>
     */
    private function parse(string $contents): array
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $rules = [];
        foreach (explode("\n", $contents) as $line) {
            $line = ltrim(rtrim($line, "\r"), " \t");
            if ('' === $line || str_starts_with($line, '#') || str_starts_with($line, '[attr]')) {
                continue;
            }
            [$pattern, $rest] = $this->splitPattern($line);
            if ('' === $pattern || str_starts_with($pattern, '!') || str_ends_with($pattern, '/')) {
                continue;
            }
            $attrs = $this->lineEndingAttributes(preg_split('/[ \t]+/', trim($rest), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            if ([] === $attrs) {
                continue;
            }
            $basenameOnly = !str_contains($pattern, '/');
            $rules[] = [
                'regex' => $this->toRegex(ltrim($pattern, '/')),
                'basename' => $basenameOnly,
                'attrs' => $attrs,
            ];
        }

        return $rules;
    }

    /**
     * Splits a line into its pattern and the rest. A pattern that starts
     * with a double quote is a C-style quoted string, as Git writes it.
     *
     * @return array{0: string, 1: string}
     */
    private function splitPattern(string $line): array
    {
        if (str_starts_with($line, '"')
            && preg_match('/^"((?:[^"\\\\]|\\\\.)*)"(.*)$/s', $line, $match) === 1) {
            return [stripcslashes($match[1]), $match[2]];
        }
        $parts = preg_split('/[ \t]+/', $line, 2) ?: [$line];

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * The line-ending attributes one line sets, as 'text' => set|unset|auto|
     * unspecified and 'eol' => lf|crlf|unspecified. binary unsets text, and
     * the old crlf attribute maps the way Git maps it: crlf is text, -crlf
     * is -text, crlf=input is eol=lf.
     *
     * @param list<string> $tokens
     *
     * @return array<string, string>
     */
    private function lineEndingAttributes(array $tokens): array
    {
        $attrs = [];
        foreach ($tokens as $token) {
            if (in_array($token, ['text', 'crlf'], true)) {
                $attrs['text'] = 'set';
            } elseif (in_array($token, ['-text', '-crlf', 'binary'], true)) {
                $attrs['text'] = 'unset';
            } elseif ('text=auto' === $token) {
                $attrs['text'] = 'auto';
            } elseif (in_array($token, ['!text', '!crlf'], true) || str_starts_with($token, 'text=')) {
                $attrs['text'] = 'unspecified';
            } elseif (in_array($token, ['eol=lf', 'crlf=input'], true)) {
                $attrs['eol'] = 'lf';
            } elseif ('eol=crlf' === $token) {
                $attrs['eol'] = 'crlf';
            } elseif (in_array($token, ['-eol', '!eol'], true) || str_starts_with($token, 'eol=')) {
                $attrs['eol'] = 'unspecified';
            }
        }

        return $attrs;
    }

    /**
     * Turns a gitattributes pattern into an anchored regular expression,
     * following Git's wildmatch: * and ? never cross a /, a leading **\/
     * and a middle /**\/ match zero or more folders, a trailing /** matches
     * everything below, [...] is a character class ([!...] negates it) and
     * a backslash makes the next character literal.
     */
    private function toRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ('*' === $char && '*' === ($pattern[$i + 1] ?? '')) {
                $atStart = 0 === $i || '/' === $pattern[$i - 1];
                $next = $pattern[$i + 2] ?? null;
                if ($atStart && '/' === $next) {
                    $regex .= '(?:.*/)?';
                    $i += 2;
                    continue;
                }
                if ($atStart && null === $next) {
                    $regex .= '.*';
                    $i += 1;
                    continue;
                }
                $regex .= '[^/]*';
                $i += 1;
                continue;
            }
            if ('*' === $char) {
                $regex .= '[^/]*';
            } elseif ('?' === $char) {
                $regex .= '[^/]';
            } elseif ('\\' === $char && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '~');
            } elseif ('[' === $char && null !== ($class = $this->characterClass($pattern, $i))) {
                [$classRegex, $i] = $class;
                $regex .= $classRegex;
            } else {
                $regex .= preg_quote($char, '~');
            }
        }

        return '~^' . $regex . '$~';
    }

    /**
     * The regex for a [...] class starting at $start and the index of its
     * closing ], or null when it never closes (then [ is literal).
     *
     * @return array{0: string, 1: int}|null
     */
    private function characterClass(string $pattern, int $start): ?array
    {
        $i = $start + 1;
        $length = strlen($pattern);
        $negate = false;
        if ($i < $length && ('!' === $pattern[$i] || '^' === $pattern[$i])) {
            $negate = true;
            $i++;
        }
        $body = '';
        $first = true;
        for (; $i < $length; $i++) {
            $char = $pattern[$i];
            if (']' === $char && !$first) {
                return ['(?!/)[' . ($negate ? '^' : '') . $body . ']', $i];
            }
            if ('[' === $char && ':' === ($pattern[$i + 1] ?? '')
                && false !== ($end = strpos($pattern, ':]', $i + 2))) {
                $body .= substr($pattern, $i, $end + 2 - $i);
                $i = $end + 1;
            } elseif ('\\' === $char && $i + 1 < $length) {
                $body .= '\\' . $pattern[++$i];
            } elseif ('-' === $char) {
                $body .= '-';
            } else {
                $body .= preg_quote($char, '~');
            }
            $first = false;
        }

        return null;
    }

    /**
     * Whether a file comes out of the .gitattributes files with eol=lf and
     * text not unset: the root file first, then each deeper one on the way
     * down to the file's folder, every matching line overriding the ones
     * before it.
     *
     * @param array<string, list<array{regex: string, basename: bool, attrs: array<string, string>}>> $rules
     */
    private function pinnedToLf(string $relativePath, array $rules): bool
    {
        $state = ['text' => 'unspecified', 'eol' => 'unspecified'];
        $segments = explode('/', $relativePath);
        $basename = array_pop($segments);

        $dirs = [''];
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = '' === $prefix ? $segment : $prefix . '/' . $segment;
            $dirs[] = $prefix;
        }

        foreach ($dirs as $dir) {
            $local = '' === $dir ? $relativePath : substr($relativePath, strlen($dir) + 1);
            foreach ($rules[$dir] ?? [] as $rule) {
                if (preg_match($rule['regex'], $rule['basename'] ? $basename : $local) === 1) {
                    $state = array_merge($state, $rule['attrs']);
                }
            }
        }

        return 'lf' === $state['eol'] && 'unset' !== $state['text'];
    }

    /**
     * The 1-based line of the first carriage return in the raw bytes, or
     * null when the file has none.
     */
    private function lineOfFirstCarriageReturn(string $raw): ?int
    {
        $offset = strpos($raw, "\r");

        return false === $offset ? null : substr_count($raw, "\n", 0, $offset) + 1;
    }

    /**
     * A warning that .gitattributes leaves some kinds unpinned, naming the
     * first unpinned file of each and the exact lines that fix it.
     *
     * @param array<string, string> $unpinned the first unpinned file, by kind
     */
    private function notPinned(array $unpinned, bool $hasGitattributes): Finding
    {
        $labels = [];
        $lines = [];
        foreach ($unpinned as $kind => $relativePath) {
            $labels[] = sprintf('%s (%s)', self::KINDS[$kind]['label'], $relativePath);
            foreach (self::KINDS[$kind]['lines'] as $line) {
                $lines[] = '"' . $line . '"';
            }
        }

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                $hasGitattributes
                    ? '.gitattributes does not pin %s to LF, so a Windows checkout can write CRLF into files a Linux container reads; add %s at the end of .gitattributes, then run "%s" and commit'
                    : 'There is no .gitattributes to pin %s to LF, so a Windows checkout can write CRLF into files a Linux container reads; add %s to a new .gitattributes, then run "%s" and commit',
                $this->joinWords($labels),
                $this->joinWords($lines),
                self::RENORMALIZE,
            ),
            '.gitattributes',
            1,
            null,
            'not-pinned',
        );
    }

    /**
     * A warning for one Dockerfile or .conf under deploy/ or .squire/ that
     * has Windows line endings on disk.
     */
    private function crlf(string $relativePath, int $line, string $kind): Finding
    {
        $basename = basename($relativePath);
        $pin = match (true) {
            'conf' === $kind => '*.conf text eol=lf',
            'Dockerfile' === $basename => 'Dockerfile text eol=lf',
            default => '*.Dockerfile text eol=lf',
        };

        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            sprintf(
                '%s has Windows line endings (CRLF), which break it in a Linux container; convert it to LF, pin it in .gitattributes ("%s", or a broader "* text=auto eol=lf"), then run "%s" and commit',
                $relativePath,
                $pin,
                self::RENORMALIZE,
            ),
            $relativePath,
            $line,
            null,
            'crlf',
        );
    }

    /**
     * Joins words as "a", "a and b" or "a, b and c".
     *
     * @param list<string> $words
     */
    private function joinWords(array $words): string
    {
        if (count($words) < 2) {
            return implode('', $words);
        }
        $last = array_pop($words);

        return implode(', ', $words) . ' and ' . $last;
    }
}

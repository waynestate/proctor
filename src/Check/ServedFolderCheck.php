<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Waynestate\Proctor\Code\PhpFiles;
use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when a debug, dump, backup or env file sits in the served folder.
 *
 * The served folder is the top-level public/ (CREED's secrets chapter:
 * Laravel's public/ is the only served folder, and raw PHP apps serve one
 * public/ folder the same way). Anything committed there can be downloaded
 * by a stranger. Flagged:
 *
 * - `proctor/served-folder/phpinfo`: a PHP file that calls phpinfo(), which
 *   prints the server's configuration and environment.
 * - `proctor/served-folder/debug-file`: a database dump (*.sql, *.sql.gz,
 *   *.dump), a backup or editor copy (*.bak, *.backup, *.old, *.orig, *.swp,
 *   *.swo, a name ending in ~), a log (*.log), an env file (.env, .env.*), or
 *   a database admin tool (adminer*.php).
 *
 * Only committed files count (the git-tracked files, or the files on disk
 * when git cannot be asked). No finding when the app has no public/ folder:
 * an app served from its root is outside what this check can see. PHP is
 * read as an AST, so phpinfo in a comment or a string is not flagged.
 *
 * Warnings, never a fail. The proctor clears the scratch paper off the desk
 * before the hall opens.
 */
final class ServedFolderCheck implements CheckInterface
{
    /** The served folder. */
    private const SERVED = 'public';

    /**
     * Name patterns for files that never belong in the served folder, with
     * what each one is.
     *
     * @var array<string, string>
     */
    private const NAME_PATTERNS = [
        '/\.(sql|sql\.gz|sql\.zip|sql\.bz2|dump)$/i' => 'a database dump',
        '/(\.(bak|backup|old|orig|swp|swo)|~)$/i' => 'a backup or editor copy',
        '/\.log$/i' => 'a log file',
        '/^\.env(\..+)?$/' => 'an env file',
        '/^adminer.*\.php$/i' => 'a database admin tool',
    ];

    public function id(): string
    {
        return 'served-folder';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        if (!is_dir($appPath . '/' . self::SERVED)) {
            return [];
        }

        $findings = [];
        $phpFiles = new PhpFiles();
        foreach (RepoFiles::list($appPath, $context->timeout)->under(self::SERVED) as $path) {
            $base = basename($path);
            $named = null;
            foreach (self::NAME_PATTERNS as $pattern => $what) {
                if (preg_match($pattern, $base) === 1) {
                    $named = $what;
                    break;
                }
            }
            if ($named !== null) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        '%s is %s in the served folder, so anyone can download it. Move it out of %s/ (or delete it) and keep it out of the repository',
                        $path,
                        $named,
                        self::SERVED,
                    ),
                    $path,
                    1,
                    null,
                    'debug-file',
                );

                continue;
            }
            if (!str_ends_with(strtolower($base), '.php')) {
                continue;
            }
            $line = $this->phpinfoLine($phpFiles, $appPath . '/' . $path);
            if ($line !== null) {
                $findings[] = new Finding(
                    $this->id(),
                    Finding::STATUS_WARNING,
                    sprintf(
                        '%s calls phpinfo() in the served folder, which shows strangers the server configuration and environment. Delete the file',
                        $path,
                    ),
                    $path,
                    $line,
                    null,
                    'phpinfo',
                );
            }
        }

        if ($findings === []) {
            return [new Finding(
                $this->id(),
                Finding::STATUS_PASS,
                'no debug, dump, backup or env files in public/',
            )];
        }

        return $findings;
    }

    /**
     * The line of the first phpinfo() call in the file, or null.
     */
    private function phpinfoLine(PhpFiles $phpFiles, string $absolute): ?int
    {
        $statements = $phpFiles->statements($absolute);
        if ($statements === null) {
            return null;
        }
        foreach ($phpFiles->findInstanceOf($phpFiles->resolveNames($statements), FuncCall::class) as $call) {
            if ($call->name instanceof Name && strtolower($call->name->toString()) === 'phpinfo') {
                return $call->getStartLine();
            }
        }

        return null;
    }
}

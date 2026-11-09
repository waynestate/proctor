<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Check;

use Waynestate\Proctor\Code\RepoFiles;

/**
 * Warns when a served app commits no robots.txt, or one that lets every
 * crawler in by default.
 *
 * CREED production says every app must set robots.txt to keep spiders out
 * unless crawling is wanted (WSU developer standards, 2024). The check
 * applies only to an app with a committed public/index.php, the served
 * folder ServedFolderCheck reads; without one it emits no finding at all.
 *
 * - `proctor/robots-txt/missing`: no committed public/robots.txt.
 * - `proctor/robots-txt/allows-all`: no group for `User-agent: *` holds
 *   `Disallow: /` or `Allow: /`. Laravel's skeleton ships `User-agent: *`
 *   and an empty `Disallow:`, which lets everyone in without anyone
 *   having decided so.
 *
 * `Disallow: /` keeps every crawler out; `Allow: /` is a written choice to
 * be crawled. Either one is a decision, so either one passes (a group with
 * both passes too). Field names are case-insensitive, `#` comments and
 * blank lines are ignored, and a group is the run of rules after one or
 * more User-agent lines (RFC 9309). `Disallow: /admin` and `Allow: /public`
 * cover one corner, not the hall, so they do not count.
 *
 * Emits proctor/robots-txt on pass. Every finding is a warning, never a
 * failure. The proctor puts a sign on the exam-room door: spectators
 * welcome, or not, but say which.
 */
final class RobotsTxtCheck implements CheckInterface
{
    /** The front controller that marks an app as served from public/. */
    private const INDEX = 'public/index.php';

    /** The file crawlers read. */
    private const ROBOTS = 'public/robots.txt';

    private const MISSING_MESSAGE = 'public/robots.txt is missing; keep crawlers out with Disallow: /, or write Allow: / when crawling is wanted (CREED production)';

    private const ALLOWS_ALL_MESSAGE = 'public/robots.txt lets every crawler in by default; keep crawlers out with Disallow: /, or write Allow: / when crawling is wanted (CREED production)';

    /** keepsOrLets() result: a `User-agent: *` group holds `Disallow: /` and none holds `Allow: /`. */
    public const KEEPS_OUT = 'keeps-out';

    /** keepsOrLets() result: a `User-agent: *` group holds `Allow: /`. */
    public const LETS_IN = 'lets-in';

    public function id(): string
    {
        return 'robots-txt';
    }

    public function run(CheckContext $context): array
    {
        $appPath = rtrim($context->appPath, '/\\');
        // Cheap test first, so an app with no served folder never runs git.
        if (!is_file($appPath . '/' . self::INDEX)) {
            return [];
        }

        $files = RepoFiles::list($appPath, $context->timeout)->files;
        if (!in_array(self::INDEX, $files, true)) {
            return [];
        }

        if (!in_array(self::ROBOTS, $files, true)) {
            return [$this->warn(self::MISSING_MESSAGE, 'missing')];
        }

        $raw = file_get_contents($appPath . '/' . self::ROBOTS);
        $decision = $raw === false ? null : self::keepsOrLets($raw);
        if ($decision === null) {
            return [$this->warn(self::ALLOWS_ALL_MESSAGE, 'allows-all')];
        }

        return [new Finding(
            $this->id(),
            Finding::STATUS_PASS,
            $decision === self::LETS_IN
                ? 'public/robots.txt lets every crawler in by choice (Allow: / for User-agent: *)'
                : 'public/robots.txt keeps every crawler out (Disallow: / for User-agent: *)',
            self::ROBOTS,
        )];
    }

    /**
     * What a robots.txt decides for every crawler: LETS_IN when a group for
     * `User-agent: *` holds `Allow: /` (RFC 9309 lets the allow win a tie,
     * so a group with both lands here too), KEEPS_OUT when one holds
     * `Disallow: /`, or null when no such group decides either way.
     *
     * A group starts at the first User-agent line after a rule (or at the
     * top of the file) and runs until the next User-agent line that follows
     * a rule. Every field but User-agent and Sitemap counts as a rule for
     * that purpose. Every `User-agent: *` group is read, as a crawler merges
     * them.
     */
    public static function keepsOrLets(string $raw): ?string
    {
        if (str_starts_with($raw, "\u{FEFF}")) {
            $raw = substr($raw, 3);
        }

        $agents = [];
        $inRules = false;
        $keepsOut = false;
        $letsIn = false;
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $hash = strpos($line, '#');
            if ($hash !== false) {
                $line = substr($line, 0, $hash);
            }
            $line = trim($line);
            if ($line === '' || preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $field = strtolower($match[1]);
            $value = trim($match[2]);

            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);

                continue;
            }
            // Sitemap belongs to no group, so it does not end the agent list.
            if ($field === 'sitemap') {
                continue;
            }

            $inRules = true;
            if ($value !== '/' || !in_array('*', $agents, true)) {
                continue;
            }
            if ($field === 'disallow') {
                $keepsOut = true;
            } elseif ($field === 'allow') {
                $letsIn = true;
            }
        }

        if ($letsIn) {
            return self::LETS_IN;
        }

        return $keepsOut ? self::KEEPS_OUT : null;
    }

    /**
     * A warning finding on public/robots.txt for the given rule.
     */
    private function warn(string $message, string $rule): Finding
    {
        return new Finding(
            $this->id(),
            Finding::STATUS_WARNING,
            $message,
            self::ROBOTS,
            1,
            null,
            $rule,
        );
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests\Check;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckContext;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Check\Finding;
use Waynestate\Proctor\Check\GitattributesEolCheck;
use Waynestate\Proctor\Creed\Policy;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * Tests the gitattributes-eol check on a temp app dir. Every fixture is
 * written at run time with real CR bytes, so Git cannot normalize them away
 * on checkout, and the dir is removed after each test.
 */
final class GitattributesEolCheckTest extends TestCase
{
    /** The whole-line default plus the four lines that pin every kind the check knows. */
    private const COMPLETE = "* text=auto eol=lf\n*.sh text eol=lf\n*.conf text eol=lf\nDockerfile text eol=lf\n*.Dockerfile text eol=lf\n";

    private const PASS = 'Shell scripts, .conf files and Dockerfiles are pinned to LF line endings.';

    private const NOTHING = 'No shell script, .conf file or Dockerfile to pin to LF.';

    private string $appPath = '';

    protected function tearDown(): void
    {
        if ($this->appPath !== '') {
            $this->remove($this->appPath);
            $this->appPath = '';
        }
    }

    public function testNoGitattributesWarnsWithEveryLineToAdd(): void
    {
        $this->writeFile('scripts/release.sh', "#!/bin/sh\n");
        $this->writeFile('docker/nginx.conf', "server {}\n");
        $this->writeFile('Dockerfile', "FROM php:8.4\n");

        $findings = $this->runCheck();

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/gitattributes-eol/not-pinned', $findings[0]->ruleId);
        self::assertSame('.gitattributes', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            'There is no .gitattributes to pin *.sh (scripts/release.sh), *.conf (docker/nginx.conf) and Dockerfile (Dockerfile) to LF, so a Windows checkout can write CRLF into files a Linux container reads; add "*.sh text eol=lf", "*.conf text eol=lf", "Dockerfile text eol=lf" and "*.Dockerfile text eol=lf" to a new .gitattributes, then run "git add --renormalize ." and commit',
            $findings[0]->message,
        );
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[1]->ruleId);
        self::assertSame('.gitattributes', $findings[1]->path);
        self::assertSame(1, $findings[1]->line);
    }

    public function testACompleteGitattributesPasses(): void
    {
        $this->writeFile('.gitattributes', self::COMPLETE);
        $this->writeFile('deploy/build.sh', "#!/bin/sh\n");
        $this->writeFile('deploy/.squire/vhost.conf', "<VirtualHost *:80>\n");
        $this->writeFile('Dockerfile', "FROM php:8.4\n");
        $this->writeFile('deploy/.squire/base.Dockerfile', "FROM php:8.4\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame('proctor/gitattributes-eol', $findings[0]->ruleId);
        self::assertSame(self::PASS, $findings[0]->message);
    }

    public function testTextAutoEolLfForEveryFilePasses(): void
    {
        $this->writeFile('.gitattributes', "* text=auto eol=lf\n*.ps1 text eol=crlf\n");
        $this->writeFile('deploy/build.sh', "#!/bin/sh\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");
        $this->writeFile('Dockerfile', "FROM php:8.4\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(self::PASS, $findings[0]->message);
    }

    public function testAMissingGitattributesWarnsForTheMissingDefault(): void
    {
        $this->writeFile('public/index.php', "<?php\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
        self::assertSame('.gitattributes', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            'Add `* text=auto eol=lf` as the first line of .gitattributes (CREED every-os), then run git add --renormalize .',
            $findings[0]->message,
        );
    }

    public function testAGitattributesWithoutTheDefaultLineWarns(): void
    {
        $this->writeFile('.gitattributes', "*.sh text eol=lf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
        self::assertSame('.gitattributes', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            'Add `* text=auto eol=lf` as the first line of .gitattributes (CREED every-os), then run git add --renormalize .',
            $findings[0]->message,
        );
    }

    public function testTheDefaultLineInEitherOrderDoesNotWarn(): void
    {
        $this->writeFile('.gitattributes', "* eol=lf text=auto\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testTheDefaultLineWithAnExtraAttributeDoesNotWarn(): void
    {
        $this->writeFile('.gitattributes', "* text=auto eol=lf export-ignore\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testACommentedOutDefaultLineDoesNotCount(): void
    {
        $this->writeFile('.gitattributes', "# * text=auto eol=lf\n*.sh text eol=lf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
    }

    public function testANestedGitattributesWithTheDefaultLineDoesNotSatisfyTheRoot(): void
    {
        $this->writeFile('.gitattributes', "*.sh text eol=lf\n");
        $this->writeFile('deploy/.gitattributes', "* text=auto eol=lf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
    }

    public function testAPartialGitattributesMissingConfWarnsWithTheOneLine(): void
    {
        $this->writeFile('.gitattributes', "*.sh text eol=lf\nDockerfile text eol=lf\n*.Dockerfile text eol=lf\n");
        $this->writeFile('deploy/build.sh', "#!/bin/sh\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");
        $this->writeFile('Dockerfile', "FROM php:8.4\n");

        $findings = $this->runCheck();

        self::assertCount(2, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/gitattributes-eol/not-pinned', $findings[0]->ruleId);
        self::assertSame(
            '.gitattributes does not pin *.conf (deploy/vhost.conf) to LF, so a Windows checkout can write CRLF into files a Linux container reads; add "*.conf text eol=lf" at the end of .gitattributes, then run "git add --renormalize ." and commit',
            $findings[0]->message,
        );
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[1]->ruleId);
        self::assertSame('.gitattributes', $findings[1]->path);
        self::assertSame(1, $findings[1]->line);
    }

    public function testACrlfDockerfileUnderDeployWarnsOnItsFirstLine(): void
    {
        $this->writeFile('.gitattributes', self::COMPLETE);
        $this->writeFile('deploy/Dockerfile', "FROM php:8.4\r\nRUN true\r\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_WARNING, $findings[0]->status);
        self::assertSame('proctor/gitattributes-eol/crlf', $findings[0]->ruleId);
        self::assertSame('deploy/Dockerfile', $findings[0]->path);
        self::assertSame(1, $findings[0]->line);
        self::assertSame(
            'deploy/Dockerfile has Windows line endings (CRLF), which break it in a Linux container; convert it to LF, pin it in .gitattributes ("Dockerfile text eol=lf", or a broader "* text=auto eol=lf"), then run "git add --renormalize ." and commit',
            $findings[0]->message,
        );
    }

    public function testACrlfConfUnderSquireWarnsOnTheLineOfItsFirstCarriageReturn(): void
    {
        $this->writeFile('.gitattributes', self::COMPLETE);
        $this->writeFile('.squire/vhost.conf', "<VirtualHost *:80>\nServerName app\nDocumentRoot /app/public\r\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/crlf', $findings[0]->ruleId);
        self::assertSame('.squire/vhost.conf', $findings[0]->path);
        self::assertSame(3, $findings[0]->line);
        self::assertStringContainsString('("*.conf text eol=lf", or a broader', $findings[0]->message);
    }

    public function testACrlfNamedDockerfileNamesItsOwnPattern(): void
    {
        $this->writeFile('.gitattributes', self::COMPLETE);
        $this->writeFile('deploy/base.Dockerfile', "FROM php:8.4\r\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('deploy/base.Dockerfile', $findings[0]->path);
        self::assertStringContainsString('("*.Dockerfile text eol=lf", or a broader', $findings[0]->message);
    }

    public function testCrlfAndAMissingPinBothWarn(): void
    {
        $this->writeFile('deploy/Dockerfile', "FROM php:8.4\r\n");

        $findings = $this->runCheck();

        self::assertCount(3, $findings);
        self::assertSame('proctor/gitattributes-eol/not-pinned', $findings[0]->ruleId);
        self::assertSame('proctor/gitattributes-eol/crlf', $findings[1]->ruleId);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[2]->ruleId);
    }

    public function testACrlfShellScriptUnderDeployIsLeftToCrlfDeployScript(): void
    {
        $this->writeFile('.gitattributes', self::COMPLETE);
        $this->writeFile('deploy/build.sh', "#!/bin/sh\r\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testACrlfConfOutsideDeployAndSquireIsNotRead(): void
    {
        $this->writeFile('.gitattributes', self::COMPLETE);
        $this->writeFile('docker/nginx.conf', "server {}\r\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
    }

    public function testALaterLineThatUnsetsTextWins(): void
    {
        $this->writeFile('.gitattributes', "* text=auto eol=lf\n*.conf -text\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/not-pinned', $findings[0]->ruleId);
        self::assertStringStartsWith('.gitattributes does not pin *.conf (deploy/vhost.conf) to LF', $findings[0]->message);
    }

    public function testALaterEolCrlfLineWins(): void
    {
        $this->writeFile('.gitattributes', "* text=auto eol=lf\n*.sh text eol=crlf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertStringStartsWith('.gitattributes does not pin *.sh (build.sh) to LF', $findings[0]->message);
    }

    public function testALaterLfLineOverridesAnEarlierCrlfOne(): void
    {
        $this->writeFile('.gitattributes', "* text eol=crlf\n" . self::COMPLETE);
        $this->writeFile('build.sh', "#!/bin/sh\n");

        self::assertSame(Finding::STATUS_PASS, $this->runCheck()[0]->status);
    }

    public function testBinaryIsNotPinned(): void
    {
        $this->writeFile('.gitattributes', "* text=auto eol=lf\nDockerfile binary\n");
        $this->writeFile('Dockerfile', "FROM php:8.4\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/not-pinned', $findings[0]->ruleId);
        self::assertStringContainsString('add "Dockerfile text eol=lf" and "*.Dockerfile text eol=lf" at the end', $findings[0]->message);
    }

    public function testTextAutoWithoutEolIsNotPinned(): void
    {
        $this->writeFile('.gitattributes', "* text=auto\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        self::assertSame('proctor/gitattributes-eol/not-pinned', $this->runCheck()[0]->ruleId);
    }

    public function testEolLfWithoutTextIsPinned(): void
    {
        $this->writeFile('.gitattributes', "*.sh eol=lf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
    }

    public function testTheOldCrlfInputAttributeIsPinned(): void
    {
        $this->writeFile('.gitattributes', "*.sh crlf=input\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
    }

    public function testACommentedOutLineDoesNotPin(): void
    {
        $this->writeFile('.gitattributes', "# *.sh text eol=lf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        self::assertSame('proctor/gitattributes-eol/not-pinned', $this->runCheck()[0]->ruleId);
    }

    public function testAnAnchoredPatternCoversOnlyItsFolder(): void
    {
        $this->writeFile('.gitattributes', "/deploy/*.conf text eol=lf\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");
        $this->writeFile('docker/nginx.conf', "server {}\n");

        $findings = $this->runCheck();

        self::assertCount(2, $findings);
        self::assertStringStartsWith('.gitattributes does not pin *.conf (docker/nginx.conf) to LF', $findings[0]->message);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[1]->ruleId);
        self::assertSame('.gitattributes', $findings[1]->path);
        self::assertSame(1, $findings[1]->line);
    }

    public function testASingleStarDoesNotCrossAFolder(): void
    {
        $this->writeFile('.gitattributes', "deploy/*.conf text eol=lf\n");
        $this->writeFile('deploy/apache/vhost.conf', "<VirtualHost *:80>\n");

        self::assertSame('proctor/gitattributes-eol/not-pinned', $this->runCheck()[0]->ruleId);
    }

    public function testDoubleStarPatternsMatchAtAnyDepth(): void
    {
        $this->writeFile('.gitattributes', "**/*.sh text eol=lf\ndeploy/**/*.conf text eol=lf\n**/Dockerfile text eol=lf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");
        $this->writeFile('deploy/apache/sites/app.conf', "<VirtualHost *:80>\n");
        $this->writeFile('docker/app/Dockerfile', "FROM php:8.4\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
    }

    public function testACharacterClassAndAQuestionMarkMatch(): void
    {
        $this->writeFile('.gitattributes', "*.[cs][oh]* text eol=lf\nDockerfil? text eol=lf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");
        $this->writeFile('Dockerfile', "FROM php:8.4\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
    }

    public function testADeeperGitattributesPinsItsOwnFolder(): void
    {
        $this->writeFile('.gitattributes', "*.sh text eol=lf\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");
        $this->writeFile('deploy/.gitattributes', "*.conf text eol=lf\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame('proctor/gitattributes-eol/no-default', $findings[0]->ruleId);
    }

    public function testADeeperGitattributesOverridesTheRoot(): void
    {
        $this->writeFile('.gitattributes', "* text=auto eol=lf\n");
        $this->writeFile('deploy/.gitattributes', "*.conf -text\n");
        $this->writeFile('deploy/vhost.conf', "<VirtualHost *:80>\n");

        $findings = $this->runCheck();

        self::assertSame('proctor/gitattributes-eol/not-pinned', $findings[0]->ruleId);
    }

    public function testACrlfGitattributesWithAByteOrderMarkIsRead(): void
    {
        $this->writeFile('.gitattributes', "\xEF\xBB\xBF* text=auto eol=lf\r\n");
        $this->writeFile('build.sh', "#!/bin/sh\n");

        self::assertSame(Finding::STATUS_PASS, $this->runCheck()[0]->status);
    }

    public function testVendorNodeModulesAndTestsAreSkipped(): void
    {
        $this->writeFile('.gitattributes', "* text=auto eol=lf\n");
        $this->writeFile('vendor/x/Dockerfile', "FROM php:8.4\r\n");
        $this->writeFile('node_modules/x/build.sh', "#!/bin/sh\r\n");
        $this->writeFile('tests/fixtures/deploy/vhost.conf', "server {}\r\n");
        $this->writeFile('deploy/vendor/Dockerfile', "FROM php:8.4\r\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(self::NOTHING, $findings[0]->message);
    }

    public function testAnAppWithNothingToPinPasses(): void
    {
        $this->writeFile('.gitattributes', "* text=auto eol=lf\n");
        $this->writeFile('public/index.php', "<?php\n");
        $this->writeFile('deploy/Dockerfile.dockerignore', "vendor\r\n");

        $findings = $this->runCheck();

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status);
        self::assertSame(self::NOTHING, $findings[0]->message);
    }

    public function testTheProctorRepoItselfPasses(): void
    {
        $context = new CheckContext(
            dirname(__DIR__, 2),
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        $findings = (new GitattributesEolCheck())->run($context);

        self::assertCount(1, $findings);
        self::assertSame(Finding::STATUS_PASS, $findings[0]->status, $findings[0]->message);
    }

    public function testRegisteredAfterCrlfDeployScriptAndNotInTheDeployGate(): void
    {
        $ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, dirname(__DIR__, 2))));

        self::assertContains('gitattributes-eol', $ids);
        self::assertSame(array_search('crlf-deploy-script', $ids, true) + 1, array_search('gitattributes-eol', $ids, true));
        self::assertNotContains('gitattributes-eol', CheckSet::GATE);
    }

    /**
     * Writes a file into the temp app dir (created on first use), making any
     * missing parent directory along the way.
     */
    private function writeFile(string $relative, string $content): void
    {
        $this->appDir();
        $absolute = $this->appPath . '/' . $relative;
        $parent = dirname($absolute);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        file_put_contents($absolute, $content);
    }

    /**
     * The temp app dir, created on first use and remembered for cleanup.
     */
    private function appDir(): string
    {
        if ($this->appPath === '') {
            $this->appPath = sys_get_temp_dir() . '/proctor-gitattributes-eol-' . uniqid('', true);
            self::assertTrue(is_dir($this->appPath) || mkdir($this->appPath, 0777, true));
        }

        return $this->appPath;
    }

    /**
     * Runs the check on the temp app and returns its findings.
     *
     * @return list<Finding>
     */
    private function runCheck(): array
    {
        $context = new CheckContext(
            $this->appDir(),
            Policy::fromFile(__DIR__ . '/../fixtures/policy.json'),
            new DateTimeImmutable('2026-10-27'),
        );

        return (new GitattributesEolCheck())->run($context);
    }

    /**
     * Removes a file or directory tree.
     */
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}

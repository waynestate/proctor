<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Proctor\Check\CheckRunner;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Gate\Baseline;
use Waynestate\Proctor\Gate\Gate;
use Waynestate\Proctor\Gate\GateVerdict;
use Waynestate\Proctor\Gate\GateWriter;
use Waynestate\Proctor\Override\OverrideFile;
use Waynestate\Proctor\ToolError;

/**
 * `proctor gate`: go or no-go for one deploy (ADR-0006, CREED's deploy-gate
 * chapter).
 *
 * It runs the deploy-time checks (CheckSet::GATE) with the app's overrides,
 * compares the failures with a baseline (the running deployment, as a
 * recorded JSON verdict or as its source directory), and exits 0 for go,
 * 1 for no-go and 2 when it could not decide. A caller that must never be
 * taken down by a gate bug, such as Citadel's deploy hook, fails open on 2.
 * The proctor at the exam-hall door: it does not mark the whole paper, it
 * only decides whether you may sit.
 */
final class GateCommand extends Command
{
    private const FORMATS = ['text', 'json'];

    protected function configure(): void
    {
        $this
            ->setName('gate')
            ->setDescription('Decide go or no-go for a deploy: the deploy-time checks, ratcheted against a baseline.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path to the application to deploy.', '.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (text, json).', 'text')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'What the running deployment already fails: a PROCTOR JSON report (the file --record writes) or the running source directory. Without it every failure blocks, as for a first deploy.')
            ->addOption('record', null, InputOption::VALUE_REQUIRED, 'Also write the JSON verdict to this file (its folder is created), whatever --format says. A later run can read it with --baseline.')
            ->addOption('policy', null, InputOption::VALUE_REQUIRED, "Path to the CREED-format policy file. Without it, PROCTOR checks the PROCTOR_POLICY environment variable, then the app's vendor/waynestate/creed/policy/policy.json, then its own bundled copy. In CI this option is refused unless PROCTOR_ALLOW_OVERRIDES=1.")
            ->addOption('today', null, InputOption::VALUE_REQUIRED, "Override today's date (YYYY-MM-DD). In CI this option is refused unless PROCTOR_ALLOW_OVERRIDES=1.")
            ->addOption('php-runtime', null, InputOption::VALUE_REQUIRED, 'The PHP version that will serve the app (X.Y.Z), for the unpatched check.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Maximum seconds for composer audit before it is killed.', '60')
            ->setHelp(<<<'HELP'
                Runs the deploy-time checks (php-floor, unpatched, laravel-eol, laravel-php, lock-committed,
                composer-audit, overrides, policy-age) and ratchets them against a baseline: a failure the baseline
                also had is held, a new one blocks, an expired override always blocks, and missing advisory data
                only warns. Exit codes: 0 go, 1 no-go, 2 the gate could not decide (fail open).
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutput ? $output->getErrorOutput() : $output;
        $writer = new GateWriter();
        $format = (string) $input->getOption('format');
        $record = $input->getOption('record');
        $record = null === $record || '' === $record ? null : (string) $record;
        $path = (string) $input->getOption('path');

        try {
            if (!in_array($format, self::FORMATS, true)) {
                throw new ToolError(sprintf('Invalid format "%s". Allowed values: text, json.', $format));
            }
            $options = RunOptions::fromInput($input, $errorOutput);

            $overrides = $this->overridesFor($options->appPath, $options);
            $checks = CheckSet::gate($options->resolved);
            $findings = $this->runChecks($options->appPath, $checks, $overrides, $options);

            $baseline = $this->baseline($input->getOption('baseline'), $checks, $options);
            $verdict = new GateVerdict(
                $options->appPath,
                $options->today,
                $options->resolved,
                CheckSet::ids($checks),
                $baseline,
                Gate::decide($findings, $baseline),
                $overrides,
            );

            $json = $writer->json($verdict);
            if (null !== $record) {
                $this->writeRecord($record, $json);
            }
            $output->writeln('json' === $format ? $json : $writer->text($verdict), OutputInterface::OUTPUT_RAW);

            return $verdict->exitCode();
        } catch (ToolError $e) {
            $errorOutput->writeln('proctor gate could not decide: ' . $e->getMessage(), OutputInterface::OUTPUT_RAW);
            $json = $writer->jsonToolError($e->getMessage(), $path);
            if (null !== $record) {
                try {
                    $this->writeRecord($record, $json);
                } catch (ToolError $recordError) {
                    $errorOutput->writeln($recordError->getMessage(), OutputInterface::OUTPUT_RAW);
                }
            }
            if ('json' === $format) {
                $output->writeln($json, OutputInterface::OUTPUT_RAW);
            } elseif ('text' === $format) {
                $output->writeln($writer->textToolError($e->getMessage()), OutputInterface::OUTPUT_RAW);
            }

            return Gate::EXIT_TOOL_ERROR;
        }
    }

    /**
     * The app's overrides, checked against every registered check: an
     * override for a check the gate skips is still valid.
     *
     * @return list<\Waynestate\Proctor\Override\Override>
     *
     * @throws ToolError
     */
    private function overridesFor(string $appPath, RunOptions $options): array
    {
        $overrides = OverrideFile::load($appPath);
        OverrideFile::assertKnownChecks($overrides, CheckSet::ids(CheckSet::all($options->resolved)));

        return $overrides;
    }

    /**
     * @param list<\Waynestate\Proctor\Check\CheckInterface> $checks
     * @param list<\Waynestate\Proctor\Override\Override>     $overrides
     *
     * @return list<\Waynestate\Proctor\Check\Finding>
     *
     * @throws ToolError
     */
    private function runChecks(string $appPath, array $checks, array $overrides, RunOptions $options): array
    {
        return (new CheckRunner(
            $appPath,
            $options->resolved->policy,
            $options->today,
            $checks,
            $overrides,
            $options->timeout,
            $options->phpRuntime,
        ))->run();
    }

    /**
     * @param list<\Waynestate\Proctor\Check\CheckInterface> $checks
     *
     * @throws ToolError
     */
    private function baseline(mixed $option, array $checks, RunOptions $options): ?Baseline
    {
        if (null === $option || '' === $option) {
            return null;
        }
        $baseline = (string) $option;
        if (is_dir($baseline)) {
            try {
                $findings = $this->runChecks($baseline, $checks, $this->overridesFor($baseline, $options), $options);
            } catch (ToolError $e) {
                throw new ToolError(sprintf('Baseline directory "%s": %s', $baseline, $e->getMessage()), 0, $e);
            }

            return Baseline::fromFindings($baseline, $findings);
        }
        if (is_file($baseline)) {
            return Baseline::fromReportFile($baseline);
        }

        throw new ToolError(sprintf(
            'Baseline "%s" does not exist. Pass a PROCTOR JSON report or the running source directory, or leave --baseline out for a first deploy.',
            $baseline,
        ));
    }

    /**
     * @throws ToolError when the file or its folder cannot be written
     */
    private function writeRecord(string $file, string $json): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ToolError(sprintf('Could not create the folder for --record "%s".', $file));
        }
        if (false === @file_put_contents($file, $json . "\n")) {
            throw new ToolError(sprintf('Could not write --record "%s".', $file));
        }
    }
}

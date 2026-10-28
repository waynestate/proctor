<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Code;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses PHP files into ASTs.
 *
 * The parser is built once and reused, so a check that scans many files pays
 * the factory cost a single time. When a file cannot be read or parsed,
 * statements() returns null and lastError() / lastErrorLine() describe the
 * problem so the caller can report a proctor.parse-error finding.
 */
final class PhpFiles
{
    private Parser $parser;

    private NodeFinder $finder;

    private ?string $lastError = null;

    private int $lastErrorLine = 1;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    /**
     * The statements of the file, or null when it cannot be read or parsed.
     *
     * @return array<Node\Stmt>|null
     */
    public function statements(string $absolutePath): ?array
    {
        $this->lastError = null;
        $this->lastErrorLine = 1;

        $code = @file_get_contents($absolutePath);
        if ($code === false) {
            $this->lastError = 'could not read the file';

            return null;
        }

        try {
            $statements = $this->parser->parse($code);
        } catch (Error $error) {
            $this->lastError = $error->getRawMessage();
            $this->lastErrorLine = max(1, $error->getStartLine());

            return null;
        }

        if ($statements === null) {
            $this->lastError = 'the parser could not recover from an error';

            return null;
        }

        return $statements;
    }

    /**
     * The statements with every name resolved against its namespace and `use`
     * lines, so `use function ini_set as set_it; set_it(...)` reads as ini_set.
     * The nodes are rewritten in place.
     *
     * @param array<Node\Stmt> $statements
     *
     * @return array<Node\Stmt>
     */
    public function resolveNames(array $statements): array
    {
        /** @var array<Node\Stmt> $resolved */
        $resolved = (new NodeTraverser(new NameResolver()))->traverse($statements);

        return $resolved;
    }

    /**
     * The parser error message for the last failed statements() call.
     */
    public function lastError(): string
    {
        return $this->lastError ?? 'unknown parse error';
    }

    /**
     * The line of the last parse error, 1-based.
     */
    public function lastErrorLine(): int
    {
        return $this->lastErrorLine;
    }

    /**
     * All nodes of the given class in the statements, in document order.
     *
     * @template TNode of Node
     *
     * @param class-string<TNode> $class
     * @param array<Node\Stmt>    $statements
     *
     * @return array<TNode>
     */
    public function findInstanceOf(array $statements, string $class): array
    {
        return $this->finder->findInstanceOf($statements, $class);
    }
}

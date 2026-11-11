<?php

declare(strict_types=1);

namespace Waynestate\Proctor\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Proctor\Check\CheckSet;
use Waynestate\Proctor\Policy\PolicyResolver;

/**
 * The front page's "whole paper" must be the exam PROCTOR actually sets.
 *
 * Three freshness sweeps in two days found the paper behind the code (41
 * checks counted while 43 ran, a missing question, the wrong New tag), so the
 * comment above the list asking people to "keep the count and the tags in
 * step with CheckSet" is now a test. It holds the list to CheckSet::all() in
 * order, its Gate tags to CheckSet::GATE, the New tags to the checks the
 * legend names, and every place the page and the docs spell the count out.
 * The proctor marks its own paper first.
 */
final class FrontPagePaperTest extends TestCase
{
    /** Numbers as the pages spell them, for the counts PROCTOR may reach. */
    private const WORDS = [
        40 => 'forty', 41 => 'forty-one', 42 => 'forty-two', 43 => 'forty-three', 44 => 'forty-four',
        45 => 'forty-five', 46 => 'forty-six', 47 => 'forty-seven', 48 => 'forty-eight', 49 => 'forty-nine',
        50 => 'fifty', 51 => 'fifty-one', 52 => 'fifty-two', 53 => 'fifty-three', 54 => 'fifty-four',
        55 => 'fifty-five',
    ];

    /** @var list<string> */
    private array $ids = [];

    private string $html = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__);
        $this->ids = CheckSet::ids(CheckSet::all(PolicyResolver::resolve(null, $root)));
        $this->html = (string) file_get_contents($root . '/site/index.html');
    }

    public function testThePaperListsEveryCheckInTheOrderItRuns(): void
    {
        self::assertSame(
            $this->ids,
            array_column($this->paperRows(), 'id'),
            'site/index.html: the whole paper must list CheckSet::all() in order; add, drop or move the <li class="pq"> rows',
        );
    }

    public function testTheStaggerIndexCountsUpFromZero(): void
    {
        self::assertSame(
            array_keys($this->ids),
            array_column($this->paperRows(), 'i'),
            'site/index.html: each paper row\'s style="--i:N" must count up from 0 with no gap, or the ticks land out of order',
        );
    }

    public function testGateTagsMarkExactlyTheGateChecks(): void
    {
        $tagged = array_values(array_column(
            array_filter($this->paperRows(), static fn(array $row): bool => $row['gate']),
            'id',
        ));
        $gate = array_values(array_filter($this->ids, static fn(string $id): bool => in_array($id, CheckSet::GATE, true)));

        self::assertSame($gate, $tagged, 'site/index.html: the Gate tags must mark exactly CheckSet::GATE');
    }

    public function testNewTagsMarkACheckAndTheLegendNamesARelease(): void
    {
        $new = array_column(
            array_filter($this->paperRows(), static fn(array $row): bool => $row['new']),
            'id',
        );
        self::assertNotSame([], $new, 'site/index.html: at least one paper row carries the New tag');

        self::assertMatchesRegularExpression(
            '/<li><span class="tag tag--new">New<\/span> (new in|on main since) \d+\.\d+\.\d+/',
            $this->html,
            'site/index.html: the paper legend must say which release the New tags belong to ("new in X.Y.Z")',
        );

        // Each New check must be named in that release's CHANGELOG section.
        preg_match('/<span class="tag tag--new">New<\/span> (?:new in|on main since) (\d+\.\d+\.\d+)/', $this->html, $legend);
        $changelog = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        $section = '';
        if (preg_match('/^## ' . preg_quote($legend[1], '/') . '\b.*?\n(.*?)(?=^## )/ms', $changelog, $match) === 1) {
            $section = $match[1];
        }
        self::assertNotSame('', $section, sprintf('CHANGELOG.md has no "## %s" section for the paper legend', $legend[1]));
        foreach ($new as $id) {
            self::assertStringContainsString(
                '`' . $id . '`',
                $section,
                sprintf('site/index.html tags %s New, but CHANGELOG.md %s does not name it', $id, $legend[1]),
            );
        }
    }

    public function testEveryCountOnThePageAndInTheDocsIsTheRealOne(): void
    {
        $count = count($this->ids);
        self::assertArrayHasKey($count, self::WORDS, 'extend WORDS: PROCTOR now runs ' . $count . ' checks');
        $word = self::WORDS[$count];
        $root = dirname(__DIR__);

        $expected = [
            'site/index.html' => [
                '<strong>' . $count . '</strong> checks',
                'Nine of PROCTOR\'s ' . $word . ' checks',
                'All ' . $word . ' questions',
                $count . ' questions, no multiple choice.',
            ],
            'README.md' => ['Nine of the ' . $word . ' checks shown'],
            'docs/guides/checks-overview.md' => [ucfirst($word) . ' checks in all.', 'Nine of the ' . $word . ' checks shown'],
            'docs/index.md' => [ucfirst($word) . ' checks, eight of them in the deploy gate'],
        ];
        foreach ($expected as $file => $phrases) {
            $text = (string) file_get_contents($root . '/' . $file);
            foreach ($phrases as $phrase) {
                self::assertStringContainsString($phrase, $text, sprintf('%s must say "%s"', $file, $phrase));
            }
        }

        self::assertSame(8, count(CheckSet::GATE), 'the pages say eight checks run at the gate; update them with CheckSet::GATE');
        self::assertStringContainsString('<strong>8</strong> at the gate', $this->html);
    }

    /**
     * The paper's rows: each check id, its stagger index and its tags.
     *
     * @return list<array{id: string, i: int, gate: bool, new: bool}>
     */
    private function paperRows(): array
    {
        self::assertSame(
            1,
            preg_match('/<ol class="paper__list">(.*?)<\/ol>/s', $this->html, $list),
            'site/index.html must have one <ol class="paper__list">',
        );
        preg_match_all('/<li class="pq" style="--i:(\d+)">(.*?)<\/li>/s', $list[1], $rows, PREG_SET_ORDER);

        $out = [];
        foreach ($rows as $row) {
            self::assertSame(1, preg_match('/<a class="pq__id"[^>]*><code>([a-z0-9-]+)<\/code><\/a>/', $row[2], $id));
            $out[] = [
                'id' => $id[1],
                'i' => (int) $row[1],
                'gate' => str_contains($row[2], 'tag--gate'),
                'new' => str_contains($row[2], 'tag--new'),
            ];
        }

        return $out;
    }
}

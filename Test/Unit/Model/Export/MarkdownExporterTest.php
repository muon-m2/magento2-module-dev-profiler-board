<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Export;

use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;
use Muon\DevProfilerBoard\Model\Export\MarkdownExporter;
use PHPUnit\Framework\TestCase;

/**
 * @see MarkdownExporter
 */
class MarkdownExporterTest extends TestCase
{
    private MarkdownExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new MarkdownExporter();
    }

    public function testTheHeaderNamesTheRunAndTheVerdict(): void
    {
        $markdown = $this->export();

        self::assertStringStartsWith('# Profiler run `abc123`', $markdown);
        self::assertStringContainsString('**UNCACHEABLE**', $markdown);
    }

    public function testAnUnknownCauseIsStatedRatherThanInvented(): void
    {
        $markdown = $this->export(['verdict' => [
            'status' => 'uncacheable',
            'summary' => 'Layout reports the page is uncacheable.',
            'causes' => [],
            'cause_known' => false,
        ]]);

        self::assertStringContainsString('cause unknown', $markdown);
    }

    public function testTheEvidenceTableCarriesTheHeadlineFacts(): void
    {
        $markdown = $this->export();

        self::assertStringContainsString('| Duration | 100.0 ms |', $markdown);
        self::assertStringContainsString('| Statements | 9 in 2 shapes |', $markdown);
        self::assertStringContainsString('Muon/cosmic-custom (observed)', $markdown);
    }

    public function testShadowedFilesAreListedWithWinnerAndLosers(): void
    {
        $markdown = $this->export();

        self::assertStringContainsString('## Shadowed files', $markdown);
        self::assertStringContainsString('`app/design/x/tokens.less`', $markdown);
        self::assertStringContainsString('`vendor/y/tokens.less`', $markdown);
    }

    public function testSqlFindingsCarryTheThresholdsTheyWereFoundAt(): void
    {
        $markdown = $this->export();

        self::assertStringContainsString('N+1 ≥ 5 executions', $markdown);
        self::assertStringContainsString('| N+1 | 9 |', $markdown);
    }

    /**
     * A pipe inside a path would otherwise split its row into extra columns wherever the Markdown
     * is finally rendered.
     */
    public function testPipesInPathsAreEscaped(): void
    {
        $markdown = $this->export(['fallback' => [[
            'file' => 'weird|name.less',
            'winner' => 'a/weird|name.less',
            'shadowed' => ['b/weird|name.less'],
        ]]]);

        self::assertStringContainsString('weird\\|name.less', $markdown);
        self::assertStringNotContainsString('`weird|name', $markdown);
    }

    public function testNewlinesInAStatementAreFlattenedToOneLine(): void
    {
        $markdown = $this->export();

        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with($line, '| N+1 |')) {
                self::assertSame(substr_count($line, '|'), 6, 'a table row grew extra columns');
            }
        }
    }

    public function testTheOutputCarriesNoTerminalEscapeSequences(): void
    {
        self::assertDoesNotMatchRegularExpression('/\x1b\[/', $this->export());
    }

    public function testSectionsWithNothingToSayAreOmittedRatherThanLeftEmpty(): void
    {
        $markdown = $this->export(['fallback' => [], 'sql' => ['groups' => []]]);

        self::assertStringNotContainsString('## Shadowed files', $markdown);
        self::assertStringNotContainsString('## SQL findings', $markdown);
        self::assertStringContainsString('## Evidence', $markdown);
    }

    /**
     * @param array<string,mixed> $analysisOverrides
     * @return string
     */
    private function export(array $analysisOverrides = []): string
    {
        return $this->exporter->export(
            [
                'token' => 'abc123',
                'captured_at' => '2026-08-14T10:00:00+00:00',
                'request' => [
                    'method' => 'GET',
                    'url' => '/en-us/',
                    'full_action' => 'cms_index_index',
                    'duration_ms' => 100.0,
                    'memory_peak_kb' => 1024,
                ],
                'context' => [
                    'store_code' => 'en-us',
                    'theme_path' => 'Muon/cosmic-custom',
                    'theme_source' => 'observed',
                ],
            ],
            $analysisOverrides + [
                'verdict' => [
                    'status' => 'uncacheable',
                    'summary' => 'block "FormKey" declares cacheable="false"',
                    'causes' => [['detail' => 'block "FormKey" declares cacheable="false"']],
                    'cause_known' => true,
                ],
                'totals' => ['statements' => 9, 'shapes' => 2, 'fallbacks' => 4, 'shadowed' => 1],
                'fallback' => [[
                    'file' => 'app/design/x/tokens.less',
                    'winner' => 'app/design/x/tokens.less',
                    'shadowed' => ['vendor/y/tokens.less'],
                ]],
                'sql' => ['groups' => [[
                    'sample' => "SELECT *\n  FROM t\n  WHERE id = ?",
                    'count' => 9,
                    'total_ms' => 12.0,
                    'origin' => 'Loader.php:88',
                    'finding' => ['kind' => QueryAnalyzer::N_PLUS_ONE, 'basis' => 'variation observed'],
                ]]],
            ],
            ['slow_ms' => 50.0, 'nplus1' => 5, 'duplicate' => 3]
        );
    }
}

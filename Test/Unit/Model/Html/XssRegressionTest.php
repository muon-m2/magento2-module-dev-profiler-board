<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfilerBoard\Test\Unit\Model\Html;

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\ZendEscaper;
use Psr\Log\LoggerInterface;
use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfilerBoard\Model\Html\FallbackPanel;
use Muon\DevProfilerBoard\Model\Html\LayoutPanel;
use Muon\DevProfilerBoard\Model\Html\OverviewPanel;
use Muon\DevProfilerBoard\Model\Run\RequestUrl;
use Muon\DevProfilerBoard\Model\Html\SqlPanel;
use Muon\DevProfilerBoard\Model\Html\Tag;
use Muon\DevProfilerBoard\Model\Html\VerdictBanner;
use Muon\DevProfilerBoard\Model\Html\Widgets;
use PHPUnit\Framework\TestCase;

/**
 * The load-bearing test in this module.
 *
 * A stored run holds the request URL, and the request URL is attacker-controlled: anyone who can
 * reach the storefront can put a payload in a path, and the profiler will faithfully record it.
 * Rendering that raw would turn a developer tool into stored XSS against the developer — on an
 * instance that, by definition, has developer mode on.
 *
 * Every panel is fed the same payload in every field it reads. If Tag's escaping is removed, these
 * fail.
 */
class XssRegressionTest extends TestCase
{
    private const PAYLOAD = '"><script>alert(1)</script>';

    private Tag $tag;

    private Widgets $ui;

    protected function setUp(): void
    {
        $this->tag = new Tag($this->escaper());
        $this->ui = new Widgets($this->tag);
    }

    /**
     * A real Escaper, not a mock.
     *
     * Mocking it would make this test assert that the renderers *call* an escaper, which is not the
     * claim — the claim is that the payload comes out inert. Escaper resolves the inline translator
     * lazily through the global ObjectManager, which does not exist in a unit test, so that one
     * collaborator is injected directly.
     *
     * @return \Magento\Framework\Escaper
     */
    private function escaper(): Escaper
    {
        $escaper = new Escaper();

        foreach ($this->lazyCollaborators() as $property => $value) {
            (new \ReflectionProperty(Escaper::class, $property))->setValue($escaper, $value);
        }

        return $escaper;
    }

    /**
     * Escaper's three lazily-resolved collaborators, supplied so no code path reaches for the
     * global ObjectManager.
     *
     * @return array<string, object>
     */
    private function lazyCollaborators(): array
    {
        return [
            'escaper' => new ZendEscaper(),
            'logger' => $this->createStub(LoggerInterface::class),
            'translateInline' => $this->createStub(InlineInterface::class),
        ];
    }

    public function testTheVerdictBannerRendersAPoisonedRunInert(): void
    {
        $banner = new VerdictBanner($this->tag, $this->ui);

        $html = $banner->render(
            $this->poisonedRun(),
            (new CacheVerdict())->verdict(['generated' => true, 'cacheable' => false]),
            ['statements' => 1, 'shapes' => 1, 'fallbacks' => 1, 'shadowed' => 1]
        );

        $this->assertInert($html);
    }

    public function testTheOverviewPanelRendersAPoisonedRunInert(): void
    {
        $panel = new OverviewPanel($this->tag, $this->ui, new RequestUrl());

        $this->assertInert($panel->render($this->poisonedRun()));
    }

    public function testTheFallbackPanelRendersPoisonedPathsInert(): void
    {
        $panel = new FallbackPanel($this->tag, $this->ui);

        $html = $panel->render(
            [[
                'type' => self::PAYLOAD,
                'file' => self::PAYLOAD,
                'module' => self::PAYLOAD,
                'winner' => self::PAYLOAD,
                'shadowed' => [self::PAYLOAD],
                'anomaly' => null,
                'lookups' => 2,
            ]],
            ['token' => 'abc123', 'fallback' => self::PAYLOAD],
            '/muon_profiler/run/view'
        );

        $this->assertInert($html);
    }

    public function testTheSqlPanelRendersAPoisonedStatementInert(): void
    {
        $panel = new SqlPanel($this->tag, $this->ui);

        $html = $panel->render(
            [
                'groups' => [[
                    'fingerprint' => self::PAYLOAD,
                    'sample' => 'SELECT * FROM t WHERE x = ' . self::PAYLOAD,
                    'count' => 9,
                    'total_ms' => 1.0,
                    'max_ms' => 1.0,
                    'origin' => self::PAYLOAD,
                    'binds' => ['id' => self::PAYLOAD],
                    'finding' => ['kind' => 'n_plus_one', 'detail' => 'x', 'basis' => self::PAYLOAD],
                ]],
                'shapes' => 1,
                'statements' => 9,
                'total_ms' => 1.0,
                'findings' => 1,
            ],
            ['token' => 'abc123', 'nplus1' => 5, 'duplicate' => 3, 'slow' => 50.0],
            '/muon_profiler/run/view'
        );

        $this->assertInert($html);
    }

    public function testTheLayoutPanelRendersPoisonedBlockNamesInert(): void
    {
        $panel = new LayoutPanel($this->tag, $this->ui);

        $html = $panel->render(
            [
                'layout' => [
                    'generated' => true,
                    'cacheable' => false,
                    'handles' => [self::PAYLOAD],
                    'uncacheable_blocks' => [[
                        'name' => self::PAYLOAD,
                        'class' => self::PAYLOAD,
                        'template' => self::PAYLOAD,
                        'in_play' => true,
                    ]],
                    'constructor_optouts' => [['origin' => self::PAYLOAD]],
                ],
            ],
            ['status' => 'uncacheable', 'summary' => self::PAYLOAD, 'causes' => [], 'cause_known' => false]
        );

        $this->assertInert($html);
    }

    /**
     * A decoded run can legitimately hold an array where a scalar belongs — a hand-edited or
     * half-written file. Escaper raises on one, and the panel rendering it would take the whole
     * board down over a single bad file.
     */
    public function testANonScalarValueDoesNotRaise(): void
    {
        self::assertSame('—', $this->tag->text(['unexpected' => 'array']));
        self::assertSame('—', $this->tag->text(new \stdClass()));
    }

    /**
     * @param string $html
     * @return void
     */
    private function assertInert(string $html): void
    {
        self::assertStringNotContainsString('<script>', $html, 'a script tag reached the output');
        self::assertStringNotContainsString('</script>', $html, 'a closing script tag reached the output');

        // The payload opens with `">` to break out of an attribute. If the literal sequence
        // survives anywhere, an attribute somewhere is escapable.
        self::assertStringNotContainsString('"><script', $html, 'attribute break-out survived');
        self::assertStringNotContainsString('alert(1)</', $html, 'raw payload survived');

        // And prove the payload was actually present, so a renderer that silently dropped the
        // field cannot pass this test by rendering nothing at all.
        self::assertStringContainsString('alert', $html, 'the payload never reached the renderer');
    }

    /**
     * @return array<string,mixed>
     */
    private function poisonedRun(): array
    {
        return [
            'token' => 'abc123',
            'captured_at' => self::PAYLOAD,
            'schema' => 1,
            'request' => [
                'method' => self::PAYLOAD,
                'url' => self::PAYLOAD,
                'full_action' => self::PAYLOAD,
                'status' => self::PAYLOAD,
                'kind' => 'page',
                'is_ajax' => false,
                'duration_ms' => 1.0,
                'memory_peak_kb' => 1024,
            ],
            'context' => [
                'store_code' => self::PAYLOAD,
                'store_id' => 1,
                'website_id' => 1,
                'theme_path' => self::PAYLOAD,
                'theme_source' => 'configured',
            ],
            'layout' => ['generated' => true, 'cacheable' => false, 'handles' => [self::PAYLOAD]],
            'fallback' => [],
            'queries' => [],
            'truncated' => ['fallback' => 3, 'queries' => 0],
        ];
    }
}

/**
 * Muon Profiler board.
 *
 * No framework, no build step, no RequireJS — served straight from the module directory by
 * Controller/Asset/Script. Anything that changes *analysis* (a threshold, a filter) submits a form
 * and reloads, so the URL always describes what is on screen; this file only does what a reload
 * would make worse: switching tabs, watching for new runs, and copying to the clipboard.
 *
 * The ledger markup here must stay in step with Model/Html/LedgerRail.php, which renders the same
 * rows server-side.
 */
(function () {
    'use strict';

    var POLL_MS = 4000;

    /* ── tabs ───────────────────────────────────────────────────────────── */

    function initTabs() {
        var tabs = Array.prototype.slice.call(document.querySelectorAll('.tab[role="tab"]'));

        if (!tabs.length) {
            return;
        }

        function select(index) {
            var opened = null;

            tabs.forEach(function (tab, i) {
                var panel = document.getElementById(tab.getAttribute('aria-controls'));

                tab.setAttribute('aria-selected', String(i === index));

                if (panel) {
                    panel.hidden = i !== index;
                }

                if (i === index) {
                    // "p-sql" → "sql", the key RunView::PANELS uses.
                    opened = (tab.getAttribute('aria-controls') || '').replace(/^p-/, '');
                }
            });

            if (opened) {
                remember(opened);
            }
        }

        // Switching a tab is a client-side move, so nothing else on the page knows it happened —
        // and every link and form that goes back to the server carries `panel`. Left alone, opening
        // SQL and then clicking Apply, Re-analyse, or another run in the ledger would land the
        // reader back on Overview. Writing the panel into the URL keeps the address bar, the
        // ledger, a reload and a shared link all describing what is actually on screen.
        function remember(panel) {
            try {
                var here = new URL(window.location.href);
                here.searchParams.set('panel', panel);
                window.history.replaceState(null, '', here.toString());
            } catch (error) {
                return; // Old browser, no URL API: the forms still carry their own panel.
            }

            Array.prototype.forEach.call(document.querySelectorAll('[data-ledger] a[href]'), function (row) {
                row.setAttribute('href', withPanel(row.getAttribute('href'), panel));
            });
        }

        function withPanel(href, panel) {
            try {
                var url = new URL(href, window.location.href);
                url.searchParams.set('panel', panel);

                return url.toString();
            } catch (error) {
                return href;
            }
        }

        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () {
                select(i);
            });

            tab.addEventListener('keydown', function (event) {
                var step = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' ? -1 : 0);

                if (!step) {
                    return;
                }

                event.preventDefault();
                var next = (i + step + tabs.length) % tabs.length;
                select(next);
                tabs[next].focus();
            });
        });
    }

    /* ── live ledger ────────────────────────────────────────────────────── */

    function initLedger() {
        var feedUrl = document.body.getAttribute('data-feed');
        var list = document.querySelector('[data-ledger]');
        var dot = document.querySelector('.dot');
        var counter = document.querySelector('[data-run-count]');
        var toggle = document.querySelector('[data-live-toggle]');

        if (!feedUrl || !list) {
            return;
        }

        var paused = false;
        var known = {};
        var timer = null;

        Array.prototype.forEach.call(list.querySelectorAll('[data-token]'), function (row) {
            known[row.getAttribute('data-token')] = true;
        });

        function rowMarkup(run) {
            var chips = [chip(run.method), chip(run.status), chip(run.verdict, run.verdict)];

            if (run.is_ajax) {
                chips.push(chip('ajax'));
            } else if (run.kind && run.kind !== 'page') {
                chips.push(chip(run.kind));
            }

            var href = templateHref(run.token);
            var summary = Number(run.duration_ms).toFixed(1) + ' ms · ' + run.statements + ' stmt · ' + run.token;
            var current = run.token === selectedToken() ? ' aria-current="true"' : '';
            var fresh = known[run.token] ? '' : ' is-new';

            return '<li><a class="run' + fresh + '" href="' + escapeAttr(href) + '"'
                + ' data-token="' + escapeAttr(run.token) + '"'
                + ' data-spine="' + escapeAttr(verdictClass(run.verdict)) + '"' + current + '>'
                + '<span class="run-top">' + chips.join('') + '</span>'
                + '<span class="run-path">' + escapeHtml(run.url) + '</span>'
                + '<span class="run-foot num">' + escapeHtml(summary) + '</span>'
                + '</a></li>';
        }

        function chip(label, verdict) {
            var cls = verdict ? 'chip ' + verdictClass(verdict) : 'chip';

            return '<span class="' + cls + '">' + escapeHtml(String(label === null ? '—' : label)) + '</span>';
        }

        // Mirrors Document::ringLabel(). The ledger is capped at feedLimit while the ring holds more,
        // so printing only the row count and calling it the ring reports a cap as a total.
        function ringLabel(shown, total, matching, filtered) {
            if (typeof total !== 'number') {
                return shown + ' listed';
            }

            if (total === 0) {
                return 'no runs yet';
            }

            if (filtered) {
                return shown < matching
                    ? shown + ' of ' + matching + ' matching · ' + total + ' in ring'
                    : matching + ' matching · ' + total + ' in ring';
            }

            return shown < total ? shown + ' of ' + total + ' in ring' : total + ' in ring';
        }

        function verdictClass(verdict) {
            switch (verdict) {
                case 'hit': return 'v-hit';
                case 'miss': return 'v-miss';
                case 'uncacheable': return 'v-bad';
                default: return 'v-none';
            }
        }

        // Reuse an existing row's href as the template so the store path prefix, the route and the
        // carried analysis state all survive — none of which this script can rebuild on its own.
        function templateHref(token) {
            var sample = list.querySelector('[data-token]');

            if (!sample) {
                return '#';
            }

            return sample.getAttribute('href').replace(/(token=)[a-f0-9]+/, '$1' + token);
        }

        function selectedToken() {
            var current = list.querySelector('[aria-current="true"]');

            return current ? current.getAttribute('data-token') : null;
        }

        // The feed is asked for the same slice the page was rendered with, so a poll cannot
        // repopulate the ledger with rows the reader just filtered out.
        function feedWithFilter() {
            try {
                var target = new URL(feedUrl, window.location.href);
                var here = new URLSearchParams(window.location.search);

                ['verdict', 'method', 'status', 'min_ms', 'max_ms', 'min_stmt', 'max_stmt'].forEach(function (key) {
                    here.getAll(key).forEach(function (value) {
                        if (value !== '') {
                            target.searchParams.append(key, value);
                        }
                    });
                    here.getAll(key + '[]').forEach(function (value) {
                        if (value !== '') {
                            target.searchParams.append(key, value);
                        }
                    });
                });

                return target.toString();
            } catch (error) {
                return feedUrl;
            }
        }

        function refresh() {
            fetch(feedWithFilter(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (!data || !Array.isArray(data.runs)) {
                        return;
                    }

                    var markup = data.runs.map(rowMarkup).join('');

                    if (markup) {
                        list.innerHTML = markup;
                    }

                    data.runs.forEach(function (run) {
                        known[run.token] = true;
                    });

                    if (counter) {
                        counter.textContent = ringLabel(data.runs.length, data.total, data.matching, data.filtered);
                    }
                })
                .catch(function () {
                    // A failed poll is not worth a console error every four seconds; the next one
                    // may well succeed, and the page is still showing a valid capture.
                });
        }

        function tick() {
            // A background tab must not keep a PHP worker busy all day — and polling the instance
            // being profiled would show up in the very measurements this board displays.
            if (!paused && document.visibilityState === 'visible') {
                refresh();
            }

            timer = window.setTimeout(tick, POLL_MS);
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                paused = !paused;
                toggle.setAttribute('aria-pressed', String(paused));
                toggle.textContent = paused ? 'Resume live' : 'Pause live';

                if (dot) {
                    dot.setAttribute('data-live', paused ? 'paused' : 'idle');
                }
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && !paused) {
                refresh();
            }
        });

        timer = window.setTimeout(tick, POLL_MS);
    }

    /* ── copy to clipboard ──────────────────────────────────────────────── */

    function initCopy() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (button) {
            button.addEventListener('click', function () {
                var label = button.textContent;

                fetch(button.getAttribute('data-copy'), { credentials: 'same-origin' })
                    .then(function (response) {
                        return response.text();
                    })
                    .then(function (text) {
                        return write(text);
                    })
                    .then(function () {
                        flash(button, 'Copied');
                    })
                    .catch(function () {
                        flash(button, 'Copy failed');
                    })
                    .then(function () {
                        window.setTimeout(function () {
                            button.textContent = label;
                        }, 1600);
                    });
            });
        });
    }

    function write(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        // Self-signed certificates are normal on a dev instance and a non-secure context has no
        // clipboard API. Falling back to a selected textarea keeps the button useful rather than
        // silently doing nothing.
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();

            try {
                document.execCommand('copy') ? resolve() : reject(new Error('copy rejected'));
            } catch (error) {
                reject(error);
            } finally {
                document.body.removeChild(area);
            }
        });
    }

    function flash(button, message) {
        button.textContent = message;
    }

    /* ── compare picker ─────────────────────────────────────────────────── */

    function initCompare() {
        var toggle = document.querySelector('[data-compare-toggle]');
        var rail = document.querySelector('.rail');
        var list = document.querySelector('[data-ledger]');

        if (!toggle || !rail || !list) {
            return;
        }

        var picking = false;
        var first = null;

        toggle.addEventListener('click', function () {
            picking = !picking;
            first = null;
            rail.classList.toggle('is-comparing', picking);
            toggle.setAttribute('aria-pressed', String(picking));
            toggle.textContent = picking ? 'Pick two runs…' : 'Compare two';
            clearPicks();
        });

        list.addEventListener('click', function (event) {
            if (!picking) {
                return;
            }

            var row = event.target.closest('[data-token]');

            if (!row) {
                return;
            }

            event.preventDefault();
            var token = row.getAttribute('data-token');

            if (!first) {
                first = token;
                row.setAttribute('data-compare-pick', 'a');

                return;
            }

            window.location.href = compareUrl(first, token);
        });

        function clearPicks() {
            Array.prototype.forEach.call(list.querySelectorAll('[data-compare-pick]'), function (row) {
                row.removeAttribute('data-compare-pick');
            });
        }

        function compareUrl(a, b) {
            var link = document.querySelector('.tab-link');
            var base = link ? link.getAttribute('href').split('?')[0] : 'compare/index';

            return base + '?a=' + encodeURIComponent(a) + '&b=' + encodeURIComponent(b);
        }
    }

    /* ── escaping ───────────────────────────────────────────────────────── */

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeAttr(value) {
        return escapeHtml(value);
    }

    /* ── confirm before a destructive submit ────────────────────────────── */

    // The board's only mutation. The confirm lives here rather than in the markup so the form still
    // submits with JavaScript disabled — the right fallback for an action whose worst outcome is
    // losing throwaway profiling data.
    function initConfirm() {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-confirm]'), function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    /* ── filter panel ───────────────────────────────────────────────────── */

    // Collapsed by default, and open whenever a filter is already set — a reader arriving on a
    // filtered link must see why the ledger is short without hunting for a toggle.
    function initFilter() {
        var toggle = document.querySelector('[data-filter-toggle]');
        var form = document.querySelector('[data-filter-form]');

        if (!toggle || !form) {
            return;
        }

        toggle.addEventListener('click', function () {
            var open = form.hidden;
            form.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));
        });

        // An empty field is not a criterion. Left named, a GET submit puts "&method=&status=" in
        // the URL — harmless to read, but the point of keeping state in the address bar is that the
        // address bar is worth sharing. Disabled controls are simply not submitted.
        form.addEventListener('submit', function () {
            Array.prototype.forEach.call(form.querySelectorAll('input[type="number"], input[type="text"], select'), function (field) {
                if (field.value === '') {
                    field.disabled = true;
                }
            });
        });
    }

    function boot() {
        initTabs();
        initLedger();
        initCopy();
        initCompare();
        initConfirm();
        initFilter();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());

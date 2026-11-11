const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, assertSafeArtifacts } = require('./ui-helpers');

/**
 * Machine-measured equivalent of the manual acceptance list of UI-04.
 *
 * The manual list of the ticket asks for zoom 200%/400%, a screen reader, the
 * Arabic locale and the admin colour schemes. A screen reader cannot be
 * automated, so this file measures everything a browser can measure and
 * leaves the screen reader to a human with the wording the ticket uses.
 *
 * Every measurement is read only: the file logs in as the owned fixture
 * actor, opens the hub views and records numbers. It never creates, revokes
 * or edits a key, so the acceptance fixture keeps its state and the restore
 * step of the acceptance run stays green.
 *
 * Run it through the remote test lab, on one of the two single-site URLs:
 *
 *   RTL_AGENT=opencode python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" \
 *     e2e --compose-file compose.yaml --base-url "$UI_BASE_URL" -- sh -c \
 *     'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test \
 *      --config=tests/E2E/playwright.remote.config.js --grep UI-MANUAL'
 *
 * The evidence of one run is written to <artifacts>/ui-manual-equivalence.json
 * with the screenshots next to it. Only numbers, view names, element names and
 * HTTP status codes are recorded; no credential value ever reaches a file.
 */

const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;
const BASE_URL = process.env.BASE_URL || process.env.XNETVN_TEST_URL || 'http://localhost:8889';
const HUB = '.xnetvn-agent-hub';
const EVIDENCE_FILE = 'ui-manual-equivalence.json';
const REFERENCE_VIEWPORT = { width: 1280, height: 900 };
/** The minimum contrast of WCAG 2.2 SC 1.4.3 for normal and for large text. */
const CONTRAST_NORMAL = 4.5;
const CONTRAST_LARGE = 3;
/**
 * The zoom levels of the manual list. Browser zoom shrinks the layout viewport
 * of the window, so zoom 200% of a 1280x900 window is a 640x450 CSS viewport
 * rendered at a device pixel ratio of 2, and zoom 400% is a 320x225 viewport at
 * a ratio of 4. The device pixel ratio is set on the context because it cannot
 * change after the context is created.
 */
const ZOOM_LEVELS = [
	{ name: 'zoom-200', factor: 2, viewport: { width: 640, height: 450 } },
	{ name: 'zoom-400', factor: 4, viewport: { width: 320, height: 225 } },
];
/**
 * The admin colour schemes of the manual list. The keys are the identifiers
 * of the WordPress scheme picker: fresh is the default scheme of WordPress,
 * light and midnight are the two named schemes of the manual list. The
 * stylesheet of every scheme is read from the picker of the profile page at
 * run time, so the file never guesses a path.
 */
const ADMIN_COLOR_SCHEMES = ['fresh', 'light', 'midnight'];
/** The views of the plugin that every variant is measured on. */
const VIEWS = [
	{ name: 'overview', url: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp', root: '.xnetvn-agent-hub[data-view="overview"]' },
	{ name: 'keys', url: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-keys', root: '.xnetvn-agent-hub[data-view="keys"]' },
];

/** The measurements of the whole run, written to the artifacts directory. */
const EVIDENCE = { base_url_host: new URL(BASE_URL).host, variants: [] };
/** The logins of the run and how many attempts each of them needed. */
const LOGIN_ATTEMPTS = [];

/**
 * Record one measured variant. Only measurements and identifiers are stored.
 *
 * The payload is written to its own file right away: Playwright restarts the
 * worker after a failed case, so a file that is only written at the end of the
 * run would lose every measurement of the cases before the failure. The name
 * of the file carries the browser project, so the three engines of the matrix
 * keep their own evidence.
 *
 * @param {string} id      Identifier of the variant.
 * @param {string} project Browser project of the run.
 * @param {object} payload Measurements of the variant.
 */
function record(id, project, payload) {
	const entry = { id, project, login_attempts: LOGIN_ATTEMPTS.slice(), ...payload };
	EVIDENCE.variants.push(entry);
	if (!ARTIFACTS_DIR) {
		return;
	}
	const directory = path.join(ARTIFACTS_DIR, 'ui-manual');
	fs.mkdirSync(directory, { recursive: true });
	fs.writeFileSync(path.join(directory, `${id}--${project}.json`), `${JSON.stringify(entry, null, 2)}\n`);
}

/**
 * Measure the layout of one view: the horizontal overflow of the document, the
 * text that a container clips instead of showing, and the elements that reach
 * outside the viewport without a container that scrolls them.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @param {string}                          root Selector of the hub root.
 * @return {Promise<object>} Layout measurements.
 */
async function measureLayout(page, root) {
	return page.evaluate((selector) => {
		const doc = document.documentElement;
		const hub = document.querySelector(selector);
		const result = {
			viewport_width: doc.clientWidth,
			document_scroll_width: doc.scrollWidth,
			document_client_width: doc.clientWidth,
			horizontal_overflow_px: doc.scrollWidth - doc.clientWidth,
			clipped_text_elements: 0,
			clipped_field_elements: 0,
			offscreen_elements: 0,
			clipped_samples: [],
		};
		if (!hub) {
			return result;
		}
		const scrollContainer = (node) => {
			let current = node;
			while (current && current !== hub) {
				const overflow = getComputedStyle(current).overflowX;
				if (overflow === 'auto' || overflow === 'scroll' || overflow === 'hidden' || overflow === 'clip') {
					return true;
				}
				current = current.parentElement;
			}
			return false;
		};
		for (const node of hub.querySelectorAll('*')) {
			const style = getComputedStyle(node);
			// An element that is not rendered has no client rectangle: it is
			// inside a closed dialog or a hidden region and never reaches a
			// reader, so it cannot break the layout and cannot be measured.
			if (style.display === 'none' || style.visibility === 'hidden' || node.getClientRects().length === 0) {
				continue;
			}
			const hidden = style.overflowX === 'hidden' || style.overflowX === 'clip';
			if (hidden && node.scrollWidth - node.clientWidth > 1) {
				// A form field keeps a long value in a scrollable box: the value
				// stays reachable by selection and by the caret, so it is
				// counted apart from the static text that cannot be read.
				const field = node.matches('input, textarea, select');
				if (field) {
					result.clipped_field_elements += 1;
				} else {
					result.clipped_text_elements += 1;
				}
				if (result.clipped_samples.length < 5) {
					const own = String(node.className || '').split(' ').filter(Boolean)[0] || '';
					result.clipped_samples.push(`${node.tagName.toLowerCase()}${own ? `.${own}` : ''}${field ? ' (form field)' : ''}`);
				}
			}
			if (scrollContainer(node)) {
				continue;
			}
			const rect = node.getBoundingClientRect();
			if (rect.width > 0 && rect.right > doc.clientWidth + 1) {
				result.offscreen_elements += 1;
			}
		}
		return result;
	}, root);
}

/**
 * Measure the contrast of the text of the plugin against the background it
 * really sits on. Elements that WCAG exempts (disabled controls, faded text),
 * elements that are not rendered and elements whose background is a gradient
 * are left out of the minimum.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @param {string}                          root Selector of the hub root.
 * @return {Promise<object>} Contrast measurements.
 */
async function measureContrast(page, root) {
	return page.evaluate(([selector, normal, large]) => {
		const hub = document.querySelector(selector);
		const measurement = {
			min_ratio: null,
			min_ratio_element: '',
			below_threshold: [],
			measured_elements: 0,
		};
		if (!hub) {
			return measurement;
		}
		const parse = (value) => {
			const match = String(value).match(/rgba?\(([^)]+)\)/);
			if (!match) {
				return null;
			}
			const parts = match[1].split(/[,\s/]+/).filter(Boolean).map(Number);
			if (parts.length < 3 || parts.some(part => Number.isNaN(part))) {
				return null;
			}
			return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
		};
		const channel = (value) => {
			const part = value / 255;
			return part <= 0.03928 ? part / 12.92 : Math.pow((part + 0.055) / 1.055, 2.4);
		};
		const luminance = (color) => 0.2126 * channel(color.r) + 0.7152 * channel(color.g) + 0.0722 * channel(color.b);
		const backgroundOf = (node) => {
			let current = node;
			while (current) {
				const style = getComputedStyle(current);
				if (style.backgroundImage !== 'none') {
					return null;
				}
				const background = parse(style.backgroundColor);
				if (background && background.a > 0) {
					if (background.a >= 1) {
						return background;
					}
					const parent = backgroundOf(current.parentElement);
					if (!parent) {
						return background;
					}
					return {
						r: background.r * background.a + parent.r * (1 - background.a),
						g: background.g * background.a + parent.g * (1 - background.a),
						b: background.b * background.a + parent.b * (1 - background.a),
						a: 1,
					};
				}
				current = current.parentElement;
			}
			return { r: 255, g: 255, b: 255, a: 1 };
		};
		const label = (node) => {
			const own = String(node.className || '').split(' ').filter(Boolean)[0] || '';
			return `${node.tagName.toLowerCase()}${own ? `.${own}` : ''}`;
		};
		for (const node of hub.querySelectorAll('*')) {
			if (node.children.length > 0) {
				continue;
			}
			const text = (node.textContent || '').trim();
			if (text === '') {
				continue;
			}
			const style = getComputedStyle(node);
			if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) < 1) {
				continue;
			}
			if (node.getClientRects().length === 0) {
				continue;
			}
			if (node.closest('[disabled], [aria-disabled="true"]')) {
				continue;
			}
			const color = parse(style.color);
			const background = backgroundOf(node);
			if (!color || !background) {
				continue;
			}
			const light = Math.max(luminance(color), luminance(background));
			const dark = Math.min(luminance(color), luminance(background));
			const ratio = (light + 0.05) / (dark + 0.05);
			const size = Number.parseFloat(style.fontSize) || 0;
			const weight = Number.parseInt(style.fontWeight, 10) || 400;
			const isLarge = size >= 24 || (size >= 18.66 && weight >= 700);
			const required = isLarge ? large : normal;
			measurement.measured_elements += 1;
			if (measurement.min_ratio === null || ratio < measurement.min_ratio) {
				measurement.min_ratio = Number(ratio.toFixed(2));
				measurement.min_ratio_element = label(node);
			}
			if (ratio < required) {
				measurement.below_threshold.push(`${label(node)}:${ratio.toFixed(2)}<${required} color=${style.color} background=${style.backgroundColor}`);
			}
		}
		return measurement;
	}, [root, CONTRAST_NORMAL, CONTRAST_LARGE]);
}

/**
 * Measure whether the animations and the transitions of the plugin are off.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @param {string}                          root Selector of the hub root.
 * @return {Promise<object>} Motion measurements.
 */
async function measureMotion(page, root) {
	return page.evaluate((selector) => {
		const hub = document.querySelector(selector);
		const measurement = {
			media_matches: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
			animated_elements: 0,
			transitioning_elements: 0,
			samples: [],
		};
		if (!hub) {
			return measurement;
		}
		const active = (value) => String(value).split(',').some(part => {
			const trimmed = part.trim();
			return trimmed !== '' && trimmed !== '0s' && trimmed !== '0ms';
		});
		for (const node of hub.querySelectorAll('*')) {
			const style = getComputedStyle(node);
			if (style.display === 'none' || node.getClientRects().length === 0) {
				continue;
			}
			const animated = style.animationName !== 'none' && active(style.animationDuration);
			const transitioning = active(style.transitionDuration);
			if (animated) {
				measurement.animated_elements += 1;
			}
			if (transitioning) {
				measurement.transitioning_elements += 1;
			}
			if ((animated || transitioning) && measurement.samples.length < 8) {
				const own = String(node.className || '').split(' ').filter(Boolean)[0] || '';
				measurement.samples.push(
					`${node.tagName.toLowerCase()}${own ? `.${own}` : ''} animation=${style.animationName}/${style.animationDuration} transition=${style.transitionProperty}/${style.transitionDuration}`,
				);
			}
		}
		return measurement;
	}, root);
}

/**
 * Run the accessibility scan of the plugin on one view. The same tags and the
 * same scope as the acceptance case UI-ACC-SITE-03 are used, so the result of
 * this file and the result of the acceptance suite mean the same thing.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<object[]>} The violations, empty when the view is clean.
 */
async function axeViolations(page) {
	const results = await new AxeBuilder({ page }).include(HUB).withTags(['wcag2a', 'wcag2aa']).analyze();
	return results.violations.map(violation => ({
		id: violation.id,
		help: violation.help,
		targets: violation.nodes.slice(0, 5).map(node => node.target.join(' ')),
		summary: violation.nodes.slice(0, 2).map(node => (node.failureSummary || '').split('\n').slice(0, 2).join(' ')).join(' | '),
	}));
}

/**
 * Open one hub view and measure everything that does not depend on the
 * emulated variant. The optional preparation runs after the page is open, so
 * an emulation that a navigation would drop is applied again for each view.
 *
 * @param {import('@playwright/test').Page} page    Page under test.
 * @param {object}                          view    View descriptor.
 * @param {Function}                        prepare Optional preparation.
 * @return {Promise<object>} Measurements of the view.
 */
async function measureView(page, view, prepare) {
	await page.goto(view.url);
	await expect(page.locator(view.root), `the hub root of ${view.name}`).toBeVisible();
	if (prepare) {
		await prepare(page, view);
	}
	const layout = await measureLayout(page, view.root);
	const contrast = await measureContrast(page, view.root);
	const motion = await measureMotion(page, view.root);
	const violations = await axeViolations(page);
	return { view: view.name, layout, contrast, motion, axe_violations: violations };
}

/**
 * Save one screenshot of a view in the artifacts directory.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @param {string}                          name File name of the screenshot.
 */
async function shoot(page, project, name) {
	if (!ARTIFACTS_DIR) {
		return;
	}
	const directory = path.join(ARTIFACTS_DIR, 'ui-manual');
	fs.mkdirSync(directory, { recursive: true });
	await page.screenshot({ path: path.join(directory, `${name}--${project}.png`), fullPage: false });
}

/**
 * Assert that a measurement of one view shows no layout defect.
 *
 * @param {object} measurement Measurement of one view.
 */
function expectNoLayoutDefect(measurement) {
	expect(measurement.layout.horizontal_overflow_px, `horizontal overflow on ${measurement.view}`).toBeLessThanOrEqual(1);
	expect(measurement.layout.clipped_text_elements, `clipped text on ${measurement.view}`).toBe(0);
	expect(measurement.layout.offscreen_elements, `elements outside the viewport on ${measurement.view}`).toBe(0);
	expect(measurement.axe_violations.map(violation => violation.id), `axe violations on ${measurement.view}`).toEqual([]);
}

/**
 * Assert that the text of one view keeps the contrast of WCAG 2.2 SC 1.4.3.
 *
 * @param {object} measurement Measurement of one view.
 */
function expectReadableContrast(measurement) {
	expect(measurement.contrast.measured_elements, `measured text elements on ${measurement.view}`).toBeGreaterThan(0);
	expect(measurement.contrast.below_threshold, `text below the contrast threshold on ${measurement.view}`).toEqual([]);
}

/**
 * Open a browser context with its own emulation options and log in.
 *
 * @param {import('@playwright/test').Browser} browser Browser of the run.
 * @param {object}                               options Context options.
 * @return {Promise<import('@playwright/test').Page>} Logged in page.
 */
async function openContext(browser, options) {
	let failure = null;
	for (let attempt = 1; attempt <= 2; attempt += 1) {
		const context = await browser.newContext(Object.assign({
			baseURL: BASE_URL,
			ignoreHTTPSErrors: true,
		}, options));
		const page = await context.newPage();
		try {
			await loginAs(page, 'administrator');
			LOGIN_ATTEMPTS.push({ attempt, result: 'signed in' });
			return page;
		} catch (error) {
			failure = error;
			LOGIN_ATTEMPTS.push({ attempt, result: `the lab dropped the sign in: ${error.message.split('\n')[0]}` });
			await context.close();
		}
	}
	throw failure;
}

/**
 * Read the stylesheets of the page, in the order the browser received them.
 * The order decides which rule wins, so it belongs in the evidence.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<string[]>} Hrefs of the stylesheets.
 */
async function stylesheetHrefs(page) {
	return page.evaluate(() => [...document.querySelectorAll('link[rel="stylesheet"]')].map(link => link.getAttribute('href') || ''));
}

test.afterAll(async () => {
	if (!ARTIFACTS_DIR) {
		return;
	}
	fs.mkdirSync(ARTIFACTS_DIR, { recursive: true });
	// The variants are merged from the files of every worker, because a failed
	// case makes Playwright restart the worker and the module state of the
	// earlier cases is gone with it.
	const directory = path.join(ARTIFACTS_DIR, 'ui-manual');
	const variants = [];
	if (fs.existsSync(directory)) {
		for (const entry of fs.readdirSync(directory).filter(name => name.endsWith('.json')).sort()) {
			variants.push(JSON.parse(fs.readFileSync(path.join(directory, entry), 'utf8')));
		}
	}
	fs.writeFileSync(
		path.join(ARTIFACTS_DIR, EVIDENCE_FILE),
		`${JSON.stringify({ base_url_host: EVIDENCE.base_url_host, variants }, null, 2)}\n`,
	);
	await assertSafeArtifacts(ARTIFACTS_DIR);
});

test('UI-MANUAL-01 the reference layout and the zoom levels keep every view readable', { tag: '@ui-manual' }, async ({ browser }, testInfo) => {
	const project = testInfo.project.name;
	const page = await openContext(browser, { viewport: REFERENCE_VIEWPORT });
	try {
		const reference = [];
		for (const view of VIEWS) {
			reference.push(await measureView(page, view));
			await shoot(page, project, `reference-1280-${view.name}`);
		}
		record('reference-1280', project, {
			description: 'the 1280x900 viewport without any emulation',
			stylesheets: await stylesheetHrefs(page),
			views: reference,
		});
		for (const measurement of reference) {
			expectNoLayoutDefect(measurement);
			expectReadableContrast(measurement);
		}

		for (const level of ZOOM_LEVELS) {
			const zoomPage = await openContext(browser, {
				viewport: level.viewport,
				deviceScaleFactor: level.factor,
			});
			const measurements = [];
			try {
				for (const view of VIEWS) {
					measurements.push(await measureView(zoomPage, view));
					await shoot(zoomPage, project, `${level.name}-${view.name}`);
				}
			} finally {
				await zoomPage.context().close();
			}
			record(level.name, project, {
				description: `browser zoom ${level.factor * 100}% of a ${REFERENCE_VIEWPORT.width}x${REFERENCE_VIEWPORT.height} window`,
				viewport: level.viewport,
				device_scale_factor: level.factor,
				views: measurements,
			});
			for (const measurement of measurements) {
				expectNoLayoutDefect(measurement);
				expectReadableContrast(measurement);
			}
		}
	} finally {
		await page.context().close();
	}
});

test('UI-MANUAL-02 the Arabic locale and the right to left direction keep every view readable', { tag: '@ui-manual' }, async ({ browser }, testInfo) => {
	const project = testInfo.project.name;
	const page = await openContext(browser, { viewport: REFERENCE_VIEWPORT, locale: 'ar' });
	try {
		const language = await page.evaluate(() => ({
			navigator_language: navigator.language,
			document_language: document.documentElement.lang,
		}));
		expect(language.navigator_language, 'the browser must run with the Arabic locale').toBe('ar');
		const prepare = async (current, view) => {
			await current.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
			const direction = await current.evaluate((selector) => {
				const hub = document.querySelector(selector);
				return hub ? getComputedStyle(hub).direction : '';
			}, view.root);
			expect(direction, `the hub of ${view.name} must be laid out from right to left`).toBe('rtl');
		};
		const measurements = [];
		for (const view of VIEWS) {
			measurements.push(await measureView(page, view, prepare));
			await shoot(page, project, `locale-ar-rtl-${view.name}`);
		}
		record('locale-ar-rtl', project, {
			description: 'the Arabic locale of the browser with dir="rtl" on the document element',
			navigator_language: language.navigator_language,
			views: measurements,
		});
		for (const measurement of measurements) {
			expectNoLayoutDefect(measurement);
			expectReadableContrast(measurement);
		}
	} finally {
		await page.context().close();
	}
});

test('UI-MANUAL-03 the admin colour schemes keep the text of every view readable', { tag: '@ui-manual' }, async ({ browser }, testInfo) => {
	const project = testInfo.project.name;
	const page = await openContext(browser, { viewport: REFERENCE_VIEWPORT });
	try {
		// The scheme picker of the profile page carries the stylesheet of every
		// scheme, so the paths come from the running WordPress and not from a
		// guess of this file.
		await page.goto('/wp-admin/profile.php');
		const picker = await page.evaluate(() => {
			const schemes = {};
			for (const input of document.querySelectorAll('input[name="admin_color"]')) {
				const holder = input.closest('.color-option');
				const url = holder ? (holder.querySelector('input.css_url') || { value: '' }).value : '';
				schemes[input.value] = url || '';
			}
			const bodyClass = [...document.body.classList].find(name => name.startsWith('admin-color-')) || '';
			return { schemes, body_class: bodyClass };
		});
		const schemeReports = [];
		for (const scheme of ADMIN_COLOR_SCHEMES) {
			if (!Object.prototype.hasOwnProperty.call(picker.schemes, scheme)) {
				throw new Error(`The admin colour scheme ${scheme} is not offered by this WordPress (offered: ${Object.keys(picker.schemes).join(', ')}).`);
			}
			const url = picker.schemes[scheme];
			const bodyClass = picker.body_class.replace(/admin-color-.*/, `admin-color-${scheme}`);
			const prepare = url
				? async (current) => {
					await current.evaluate(async ({ href, name }) => {
						for (const existing of [...document.body.classList]) {
							if (existing.startsWith('admin-color-')) {
								document.body.classList.remove(existing);
							}
						}
						document.body.classList.add(name);
						await new Promise((resolve, reject) => {
							const link = document.createElement('link');
							link.rel = 'stylesheet';
							link.href = href;
							link.dataset.xnetvnUiAdminColor = '1';
							link.addEventListener('load', () => resolve());
							link.addEventListener('error', () => reject(new Error('The admin colour stylesheet did not load.')));
							document.head.appendChild(link);
						});
					}, { href: url, name: bodyClass });
				}
				: async () => {};
			const measurements = [];
			for (const view of VIEWS) {
				measurements.push(await measureView(page, view, prepare));
				await shoot(page, project, `admin-color-${scheme}-${view.name}`);
			}
			schemeReports.push({ name: scheme, url, body_class: bodyClass, views: measurements });
		}
		record('admin-color-schemes', project, {
			description: 'the WordPress admin colour schemes, emulated with the scheme stylesheet of the picker and the matching body class',
			offered_schemes: picker.schemes,
			active_body_class: picker.body_class,
			schemes: schemeReports,
		});
		for (const scheme of schemeReports) {
			for (const measurement of scheme.views) {
				expectNoLayoutDefect(measurement);
				expectReadableContrast(measurement);
			}
		}
	} finally {
		await page.context().close();
	}
});

test('UI-MANUAL-04 the colour scheme of the browser and the forced colours keep the text readable', { tag: '@ui-manual' }, async ({ browser }, testInfo) => {
	const project = testInfo.project.name;
	const page = await openContext(browser, { viewport: REFERENCE_VIEWPORT });
	try {
		const variants = [
			{ name: 'prefers-color-scheme-light', media: { colorScheme: 'light' }, query: '(prefers-color-scheme: light)' },
			{ name: 'prefers-color-scheme-dark', media: { colorScheme: 'dark' }, query: '(prefers-color-scheme: dark)' },
			{ name: 'forced-colors-active', media: { forcedColors: 'active' }, query: '(forced-colors: active)' },
		];
		const reports = [];
		for (const variant of variants) {
			await page.emulateMedia(variant.media);
			const supported = await page.evaluate((query) => window.matchMedia(query).matches, variant.query);
			const measurements = [];
			for (const view of VIEWS) {
				measurements.push(await measureView(page, view));
				await shoot(page, project, `${variant.name}-${view.name}`);
			}
			reports.push({ name: variant.name, media: variant.media, supported, views: measurements });
		}
		record('browser-colour-variants', project, {
			description: 'the colour scheme of the browser and the forced colours of the operating system',
			variants: reports,
		});
		for (const report of reports) {
			for (const measurement of report.views) {
				expectNoLayoutDefect(measurement);
				expectReadableContrast(measurement);
			}
		}
	} finally {
		await page.context().close();
	}
});

test('UI-MANUAL-05 the reduced motion turns the animations and the transitions off', { tag: '@ui-manual' }, async ({ browser }, testInfo) => {
	const project = testInfo.project.name;
	const page = await openContext(browser, { viewport: REFERENCE_VIEWPORT });
	try {
		await page.emulateMedia({ reducedMotion: 'reduce' });
		const measurements = [];
		for (const view of VIEWS) {
			measurements.push(await measureView(page, view));
			await shoot(page, project, `reduced-motion-${view.name}`);
		}
		record('prefers-reduced-motion', project, {
			description: 'the reduced motion media query of the browser',
			views: measurements,
		});
		for (const measurement of measurements) {
			expect(measurement.motion.media_matches, `the reduced motion media query on ${measurement.view}`).toBe(true);
			expect(measurement.motion.animated_elements, `animated elements on ${measurement.view}`).toBe(0);
			expect(measurement.motion.transitioning_elements, `transitioning elements on ${measurement.view}`).toBe(0);
			expectNoLayoutDefect(measurement);
		}
	} finally {
		await page.context().close();
	}
});

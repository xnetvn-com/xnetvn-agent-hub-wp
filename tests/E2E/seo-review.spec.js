// Read-only browser verification of the retained SEO review article.
//
// The spec reads the allowlisted handoff written by run-seo-review.sh, opens
// the published permalink on the lab site and compares the MCP verdict with
// the rendered DOM field by field. It never logs in, never creates a fixture,
// never cleans up the article and never captures credentials or request
// bodies: the review article stays for the project owner after every run.
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { assertSafeArtifacts } = require('./ui-helpers');

const HANDOFF_DIR = '/work/.local/seo-review';
const CAMPAIGN = /^review-[0-9]{8}T[0-9]{6}Z$/;
const TARGETS = ['wp69', 'wp71'];

/** Load and validate the allowlisted public handoff. */
function loadHandoff() {
	const raw = process.env.XNETVN_SEO_REVIEW_HANDOFF;
	if (!raw) {
		throw new Error('XNETVN_SEO_REVIEW_HANDOFF is required; browser checks never seed or infer the article.');
	}
	const resolved = path.resolve('/work', raw);
	if (!resolved.startsWith(`${HANDOFF_DIR}/`) || !fs.existsSync(resolved) || fs.lstatSync(resolved).isSymbolicLink()) {
		throw new Error('The handoff must be a regular file under the owned review root.');
	}
	const data = JSON.parse(fs.readFileSync(resolved, 'utf8'));
	if (data.suite !== 'seo-review' || !TARGETS.includes(data.target) || !CAMPAIGN.test(data.campaign)) {
		throw new Error('The handoff does not describe an owned review campaign.');
	}
	if (!Number.isInteger(data.post?.id) || data.post.id < 1 || typeof data.post.url !== 'string' || !data.post.url.startsWith('https://')) {
		throw new Error('The handoff has no published article.');
	}
	if (data.post.status !== 'publish') {
		throw new Error('The handoff article is not published; browser verification is blocked.');
	}
	return data;
}

const handoff = loadHandoff();
const postPath = new URL(handoff.post.url).pathname;
const siteHost = new URL(handoff.site_url).host;

/** Record the MCP verdict next to the DOM verdict for one field. */
function annotate(field, mcp, dom) {
	test.info().annotations.push({ type: field, description: `mcp=${mcp}; dom=${dom}` });
}

test.describe('retained SEO review article', () => {
	test.beforeAll(() => {
		const base = process.env.BASE_URL;
		if (!base || new URL(base).host !== siteHost) {
			throw new Error('BASE_URL must point at the handoff site; no local or foreign browser fallback is allowed.');
		}
	});

	test.beforeEach(async ({ page }) => {
		const response = await page.goto(postPath);
		expect(response && response.status()).toBe(200);
		await expect(page.locator('body')).toBeVisible();
		await expect(page.locator('.wp-block-post-content')).toBeVisible();
	});

	test('title is one H1 without a duplicated heading', async ({ page }) => {
		const headings = page.locator('h1');
		await expect(headings).toHaveCount(1);
		await expect(headings.first()).toHaveText(handoff.post.title);
		await expect(page.locator('.wp-block-post-content h1')).toHaveCount(0);
		annotate('title', handoff.checks.title === true, 'h1 matches the planned title');
	});

	test('heading hierarchy is ordered', async ({ page }) => {
		const content = page.locator('.wp-block-post-content');
		await expect(content.locator('h2').first()).toBeVisible();
		expect(await content.locator('h2').count()).toBeGreaterThanOrEqual(4);
		expect(await content.locator('h3').count()).toBeGreaterThanOrEqual(3);
		const order = await content.locator('h1, h2, h3').evaluateAll(nodes => nodes.map(node => Number(node.tagName.slice(1))));
		let seenH2 = false;
		for (const level of order) {
			expect(level).not.toBe(1);
			if (level === 2) {
				seenH2 = true;
			}
			if (level === 3) {
				expect(seenH2).toBe(true);
			}
		}
		annotate('structure', handoff.checks.structure === true, 'ordered H2 and H3 without H1 in content');
	});

	test('paragraphs, strong text, list and links render', async ({ page }) => {
		const content = page.locator('.wp-block-post-content');
		expect(await content.locator('p').count()).toBeGreaterThanOrEqual(6);
		expect(await content.locator('strong').count()).toBeGreaterThanOrEqual(2);
		expect(await content.locator('li').count()).toBeGreaterThanOrEqual(3);
		const internal = content.locator('a[href*="/category/"]');
		expect(await internal.count()).toBeGreaterThanOrEqual(1);
		const external = content.locator('a[href^="https://"]');
		const hrefs = await external.evaluateAll(nodes => nodes.map(node => node.getAttribute('href')));
		expect(hrefs).toContain('https://wordpress.org/documentation/article/roles-and-capabilities/');
		expect(hrefs).toContain('https://developers.google.com/search/docs/fundamentals/seo-starter-guide');
		annotate('links', handoff.checks.links === true, 'internal category link and both official HTTPS sources present');
	});

	test('featured and inline images render with meaningful alt text', async ({ page }) => {
		const featured = page.locator('.wp-block-post-featured-image img');
		const featuredCount = await featured.count();
		if (featuredCount > 0) {
			await expect(featured.first()).toBeVisible();
			expect(await featured.first().evaluate(node => node.naturalWidth)).toBeGreaterThan(0);
			const alt = await featured.first().getAttribute('alt');
			expect(alt && alt.trim().length >= 12).toBeTruthy();
			annotate('featured', handoff.checks.featured === true, 'featured image rendered with non-zero size and alt text');
		} else {
			annotate('featured', handoff.checks.featured === true, 'theme does not render the featured image; MCP binding only');
		}
		const inline = page.locator('.wp-block-post-content figure img');
		const inlineCount = await inline.count();
		expect(inlineCount).toBeGreaterThanOrEqual(2);
		for (let index = 0; index < inlineCount; index += 1) {
			const image = inline.nth(index);
			await expect(image).toBeVisible();
			expect(await image.evaluate(node => node.naturalWidth)).toBeGreaterThan(0);
			const alt = await image.getAttribute('alt');
			expect(alt && alt.trim().length >= 12).toBeTruthy();
			expect(alt.endsWith('.png')).toBe(false);
		}
		expect(await page.locator('.wp-block-post-content figcaption').count()).toBeGreaterThanOrEqual(2);
		annotate('media', handoff.checks.media === true && handoff.checks.assets === true, `${inlineCount} inline images with alt and captions`);
	});

	test('taxonomy renders and theme limits are reported', async ({ page }) => {
		const terms = page.locator('.wp-block-post-terms a');
		const count = await terms.count();
		const hrefs = count > 0 ? await terms.evaluateAll(nodes => nodes.map(node => node.getAttribute('href'))) : [];
		const categoryCount = hrefs.filter(href => typeof href === 'string' && href.includes('/category/')).length;
		const tagCount = hrefs.filter(href => typeof href === 'string' && href.includes('/tag/')).length;
		if (count === 0) {
			annotate('taxonomy', handoff.checks.taxonomy === true, 'theme does not render post terms; MCP binding only');
		} else {
			expect(categoryCount).toBeGreaterThanOrEqual(1);
			expect(tagCount).toBeGreaterThanOrEqual(2);
			annotate('taxonomy', handoff.checks.taxonomy === true, `${categoryCount} category and ${tagCount} tag links`);
		}
		// The excerpt and any SEO plugin field stay MCP-only: the theme may not
		// render them and no reviewed tool sets a meta description.
		annotate('excerpt', handoff.checks.excerpt === true, 'not asserted in the DOM; excerpt is MCP-verified');
	});

	test.afterAll(async () => {
		await assertSafeArtifacts(process.env.RTL_ARTIFACTS_DIR || '/work/.local/execution/browser-artifacts');
	});
});

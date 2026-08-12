import { visit } from 'unist-util-visit';

/**
 * Prefixes root-relative links in Markdown with the site's `base` path.
 *
 * Astro rewrites nothing in Markdown link targets — verified against 7.2.1 with
 * four forms (relative `.md`, relative directory, root-relative, root-relative
 * with the base already applied): all four reach the HTML byte-for-byte as
 * written. So a site published under a subpath needs the base applied somewhere,
 * and the only two places are the content or the build.
 *
 * Putting it in the content would mean writing `/system-markdown-alternate/…`
 * into every article, which hardcodes one deployment into files whose whole
 * point is to be the portable source — usable by a static site, a WordPress
 * import, or anything else. Doing it here keeps the articles deployment-neutral
 * and makes moving to a custom domain a one-line config change (`base: '/'`,
 * which turns this into a no-op) rather than a find-and-replace across the set.
 *
 * External URLs, protocol-relative URLs, anchors and anything already carrying
 * the prefix are left alone.
 */
export function remarkBasePaths({ base = '/' } = {}) {
	const prefix = base === '/' ? '' : `/${base.replace(/^\/|\/$/g, '')}`;

	return () => (tree) => {
		if (!prefix) {
			return;
		}

		visit(tree, 'link', (node) => {
			const url = node.url;

			if (
				typeof url !== 'string' ||
				!url.startsWith('/') ||
				url.startsWith('//') ||
				url === prefix ||
				url.startsWith(`${prefix}/`)
			) {
				return;
			}

			node.url = prefix + url;
		});
	};
}

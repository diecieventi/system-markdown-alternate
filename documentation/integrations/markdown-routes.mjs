import { readdir, readFile, mkdir, writeFile } from 'node:fs/promises';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Emits a `.md` twin of every page next to the built HTML.
 *
 * `/getting-started/what-it-does/` gets `/getting-started/what-it-does.md`,
 * which is the same shape the plugin itself serves. On static hosting this is
 * the only way to offer a Markdown representation at all: there is no server to
 * negotiate on `Accept`, so the dedicated URL is the whole mechanism — which is
 * exactly the advice these articles give for hosts whose cache answers first.
 *
 * Two rewrites are applied to the emitted copy, and neither touches the source:
 *
 * 1. `base` is prepended to root-relative links, for the same reason the site's
 *    HTML needs it — the articles are written deployment-neutral.
 * 2. Internal links are pointed at the `.md` twin rather than the HTML page, so
 *    a client that followed one Markdown document stays in Markdown instead of
 *    being handed markup halfway through.
 */
export function markdownRoutes({ base = '/', contentDir = 'src/content/docs' } = {}) {
	const prefix = base === '/' ? '' : `/${base.replace(/^\/|\/$/g, '')}`;

	return {
		name: 'sysmda:markdown-routes',
		hooks: {
			'astro:build:done': async ({ dir, logger }) => {
				const outDir = fileURLToPath(dir);
				const sourceDir = join(process.cwd(), contentDir);
				const files = await collect(sourceDir);
				let written = 0;

				for (const file of files) {
					const route = relative(sourceDir, file).replace(/\.md$/, '');
					const target = join(outDir, `${route}.md`);
					const body = rewriteLinks(await readFile(file, 'utf8'), prefix);

					await mkdir(dirname(target), { recursive: true });
					await writeFile(target, body, 'utf8');
					written++;
				}

				logger.info(`Emitted ${written} Markdown route${written === 1 ? '' : 's'}`);
			},
		},
	};
}

async function collect(dir) {
	const entries = await readdir(dir, { withFileTypes: true });
	const files = [];

	for (const entry of entries) {
		const full = join(dir, entry.name);

		if (entry.isDirectory()) {
			files.push(...(await collect(full)));
		} else if (entry.name.endsWith('.md') && !entry.name.startsWith('_')) {
			files.push(full);
		}
	}

	return files;
}

/**
 * Rewrites `](/section/article/)` to `](<base>/section/article.md)`.
 *
 * Deliberately narrow: only Markdown link targets that are root-relative, so
 * external URLs, anchors and anything already prefixed are left alone. Applied
 * to the emitted copy only — the source stays deployment-neutral.
 */
function rewriteLinks(markdown, prefix) {
	return markdown.replace(/\]\((\/[^)\s]*)\)/g, (match, url) => {
		if (url.startsWith('//') || (prefix && url.startsWith(`${prefix}/`))) {
			return match;
		}

		const target = url.endsWith('/') ? `${url.slice(0, -1)}.md` : url;

		return `](${prefix}${target})`;
	});
}

import { readdir, readFile, mkdir, writeFile, access } from 'node:fs/promises';
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
export function markdownRoutes({ base = '/', site = '', contentDir = 'src/content/docs' } = {}) {
	const prefix = base === '/' ? '' : `/${base.replace(/^\/|\/$/g, '')}`;

	return {
		name: 'sysmda:markdown-routes',
		hooks: {
			'astro:build:done': async ({ dir, logger }) => {
				const outDir = fileURLToPath(dir);
				const sourceDir = join(process.cwd(), contentDir);
				const files = await collect(sourceDir);
				const pages = [];

				for (const file of files) {
					const route = relative(sourceDir, file).replace(/\.md$/, '');
					const target = join(outDir, `${route}.md`);
					const source = await readFile(file, 'utf8');

					await mkdir(dirname(target), { recursive: true });
					await writeFile(target, rewriteLinks(source, prefix), 'utf8');

					pages.push({ route, ...frontMatter(source) });
				}

				logger.info(`Emitted ${pages.length} Markdown route${pages.length === 1 ? '' : 's'}`);

				const listed = await listPagesInLlmsTxt(outDir, pages, `${site}${prefix}`);
				if (listed) {
					logger.info('Listed them in llms.txt');
				}
			},
		},
	};
}

/**
 * Appends a page index to the `llms.txt` that starlight-llms-txt generates.
 *
 * That plugin writes an entrypoint pointing at two aggregate files — the whole
 * corpus in one document — and lists no individual page, so nothing in it leads
 * to the `.md` twins emitted above. Both shapes are useful and they answer
 * different questions: "give me everything" and "give me that one page, at a
 * URL I can cite". The spec's own examples list pages, and the plugin this site
 * documents lists them too, so the index is completed rather than replaced.
 *
 * Skipped silently when the file is absent, so removing the plugin does not
 * break the build.
 */
async function listPagesInLlmsTxt(outDir, pages, baseUrl) {
	const path = join(outDir, 'llms.txt');

	try {
		await access(path);
	} catch {
		return false;
	}

	const lines = pages
		.filter((page) => page.route !== 'index')
		.sort((a, b) => a.route.localeCompare(b.route))
		.map((page) => {
			const url = `${baseUrl}/${page.route}.md`;
			return `- [${page.title}](${url})${page.description ? `: ${page.description}` : ''}`;
		});

	const section = ['## Documentation pages', '', ...lines].join('\n');
	const current = await readFile(path, 'utf8');

	// Before `## Optional`, which the spec reserves for content a client may
	// skip when working to a budget. These pages are not that.
	const optional = current.indexOf('\n## Optional');
	const next =
		optional === -1
			? `${current.trimEnd()}\n\n${section}\n`
			: `${current.slice(0, optional).trimEnd()}\n\n${section}\n${current.slice(optional)}`;

	await writeFile(path, next, 'utf8');

	return true;
}

/** Reads `title` and `description` out of a Starlight front-matter block. */
function frontMatter(source) {
	const end = source.startsWith('---\n') ? source.indexOf('\n---\n', 3) : -1;
	const block = end === -1 ? '' : source.slice(4, end);
	const read = (key) => {
		const match = block.match(new RegExp(`^${key}: "(.*)"$`, 'm'));
		return match ? match[1].replace(/\\"/g, '"').replace(/\\\\/g, '\\') : '';
	};

	return { title: read('title'), description: read('description') };
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

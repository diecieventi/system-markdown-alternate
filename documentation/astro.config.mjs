// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import { remarkBasePaths } from './remark-base-paths.mjs';
import { markdownRoutes } from './integrations/markdown-routes.mjs';
import starlightLlmsTxt from 'starlight-llms-txt';

// GitHub Pages serves the site from a subpath of the user domain. Both values
// have to agree with where it is actually published, or every internal link and
// asset URL comes out wrong — silently, because the build still succeeds.
// Moving to a custom domain later means setting `site` to it and dropping `base`.
const SITE = 'https://diecieventi.github.io';
const BASE = '/system-markdown-alternate';

const REPO = 'https://github.com/diecieventi/system-markdown-alternate';

export default defineConfig({
	site: SITE,
	base: BASE,
	markdown: {
		// Articles link each other as `/section/article/`, with no base. Astro
		// passes Markdown link targets through untouched, so the base is applied
		// here instead of being written into the files — see remark-base-paths.mjs.
		remarkPlugins: [remarkBasePaths({ base: BASE })],
	},
	integrations: [
		// Emits `/section/article.md` next to each built page. Static hosting
		// cannot negotiate on Accept, so the dedicated URL is the whole mechanism.
		markdownRoutes({ base: BASE, site: SITE }),
		starlight({
			title: 'System Markdown Alternate',
			// The plugin's own icon, shared with the wordpress.org listing.
			// Starlight defaults to /favicon.svg, which does not exist here.
			favicon: '/favicon.png',
			description:
				'A clean Markdown version of every published post, for LLMs, agents and technical tools.',
			social: [{ icon: 'github', label: 'GitHub', href: REPO }],
			editLink: {
				baseUrl: `${REPO}/edit/main/documentation/`,
			},
			lastUpdated: true,
			plugins: [
				starlightLlmsTxt({
					projectName: 'System Markdown Alternate',
					description:
						'A WordPress plugin that exposes a clean Markdown version of every published post. Appending `.md` to a permalink returns the article as a Markdown document with YAML front matter, intended for LLMs, agents and technical tools rather than for browsers.',
					details: [
						'Notes for interpreting this documentation:',
						'',
						'- The plugin is installed on a WordPress site; these pages describe how to configure it, not how to use a hosted service.',
						'- Two representations exist for each post on a site running the plugin: the HTML permalink and the same URL with `.md` appended. The Markdown one is sent with `X-Robots-Tag: noindex, follow` and a canonical link back to the HTML page.',
						'- This documentation site is static, so unlike a site running the plugin it cannot negotiate on `Accept`. Its own Markdown twins are served at `<page URL without trailing slash>.md`.',
					].join('\n'),
					// Installing and serving a first file comes before configuration.
					promote: ['index', 'getting-started/**'],
					optionalLinks: [
						{
							label: 'Developer extension API',
							url: `${REPO}/blob/main/docs/filters.md`,
							description: 'Every filter, its default and its stability level.',
						},
						{
							label: 'Markdown output format',
							url: `${REPO}/blob/main/docs/output-format.md`,
							description: 'The document contract: front matter keys, body pipeline, HTTP responses.',
						},
					],
				}),
			],
			// Adds the copy / view / download control beside the page heading.
			components: {
				PageTitle: './src/components/PageTitle.astro',
			},
			// A group carries the label; the autogenerate config goes inside its
			// `items`. Putting `autogenerate` next to `label` was the shape until
			// Starlight 0.39.0 and is now a build error, not a warning.
			sidebar: [
				{ label: 'Getting started', items: [{ autogenerate: { directory: 'getting-started' } }] },
				{ label: 'Settings reference', items: [{ autogenerate: { directory: 'settings' } }] },
				{ label: 'Endpoints and output', items: [{ autogenerate: { directory: 'endpoints' } }] },
				{ label: 'Shortcodes', items: [{ autogenerate: { directory: 'shortcodes' } }] },
				{ label: 'Integrations', items: [{ autogenerate: { directory: 'integrations' } }] },
				{ label: 'Developers', items: [{ autogenerate: { directory: 'developers' } }] },
				{ label: 'Troubleshooting', items: [{ autogenerate: { directory: 'troubleshooting' } }] },
			],
		}),
	],
});

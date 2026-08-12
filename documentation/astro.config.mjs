// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import { remarkBasePaths } from './remark-base-paths.mjs';

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

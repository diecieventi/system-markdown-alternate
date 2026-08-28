#!/usr/bin/env node
/**
 * Regenerates the wordpress.org listing icon and banner PNGs (plus the
 * shipped icon.svg copy) from the hand-authored SVG sources in this folder.
 *
 * icon.svg and banner.svg are the single source of truth for the artwork;
 * the four PNGs in .wordpress-org/ are rasterized from them so the 1x/retina
 * pairs stay proportional by construction. Re-run this after editing either
 * SVG — do not hand-edit the PNGs.
 *
 * Prerequisites (not part of the plugin's PHP toolchain, dev machine only):
 *   - Node.js
 *   - the `playwright` package with its Chromium browser installed, e.g.
 *     `npm install -g playwright && npx playwright install chromium`
 *
 * Usage (from the repository root):
 *   node bin/wordpress-org-assets/render.js
 */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const SRC_DIR = __dirname;
const OUT_DIR = path.join(__dirname, '..', '..', '.wordpress-org');

const TARGETS = [
	{ svg: 'icon.svg', out: 'icon-128x128.png', width: 128, height: 128 },
	{ svg: 'icon.svg', out: 'icon-256x256.png', width: 256, height: 256 },
	{ svg: 'banner.svg', out: 'banner-772x250.png', width: 772, height: 250 },
	{ svg: 'banner.svg', out: 'banner-1544x500.png', width: 1544, height: 500 },
];

async function renderSvgToPng( browser, svgPath, outPath, width, height ) {
	const svg = fs.readFileSync( svgPath, 'utf8' );
	const html = `<!doctype html><html><head><style>
		html,body{margin:0;padding:0;background:transparent;}
		svg{display:block;width:${ width }px;height:${ height }px;}
	</style></head><body>${ svg }</body></html>`;
	const tmpHtml = svgPath + '.render.html';
	fs.writeFileSync( tmpHtml, html );

	const page = await browser.newPage( { viewport: { width, height }, deviceScaleFactor: 1 } );
	try {
		await page.goto( 'file://' + path.resolve( tmpHtml ) );
		await page.screenshot( { path: outPath } );
	} finally {
		await page.close();
		fs.unlinkSync( tmpHtml );
	}
	console.log( 'wrote', path.relative( process.cwd(), outPath ), `${ width }x${ height }` );
}

( async () => {
	const browser = await chromium.launch();
	try {
		for ( const target of TARGETS ) {
			await renderSvgToPng(
				browser,
				path.join( SRC_DIR, target.svg ),
				path.join( OUT_DIR, target.out ),
				target.width,
				target.height
			);
		}
	} finally {
		await browser.close();
	}

	// icon.svg is also shipped as-is (optional but recommended per the
	// wordpress.org spec: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
	const shippedSvg = path.join( OUT_DIR, 'icon.svg' );
	fs.copyFileSync( path.join( SRC_DIR, 'icon.svg' ), shippedSvg );
	console.log( 'wrote', path.relative( process.cwd(), shippedSvg ) );
} )();

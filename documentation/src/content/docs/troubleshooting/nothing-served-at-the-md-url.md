---
title: "Nothing is served at the .md URL"
description: "A 404, a redirect or a block page instead of Markdown. The checks that resolve almost every case, in the order worth trying them."
sidebar:
  order: 1
---

Work through these in order. The first three cover almost every report.

## 1. Is the content type enabled?

By far the most common cause. The plugin ships inactive: with no content type ticked under **Settings → Markdown Alternate → General**, every `.md` URL on the site returns 404. Tick at least one and save.

The same applies one type at a time: enabling *Posts* does nothing for a custom post type until that type is ticked too.

## 2. Is this particular post eligible?

A post is served only when it is published, carries no password, and uses the standard post format. Any of these returns 404 by design:

- a draft, pending, scheduled or private post;
- a password-protected post — including for a visitor who has already entered the password, because the rule is about the content, not the reader;
- a post whose format is aside, status, quote, link, image, video, audio, gallery or chat;
- a post rendered by a page builder — Bricks, Elementor, Divi, WPBakery, Oxygen, Beaver Builder or Breakdance. This is decided per post, from the builder's own render mode, so a site whose pages use a builder keeps every article that does not. See [Page builders](/integrations/page-builders/).

A quick way to tell the difference between "not eligible" and "not working": if the post were eligible, its HTML page would carry a Markdown `alternate` link. View source and search for `text/markdown`. Nothing there means the post is being excluded, not that the endpoint is broken.

## 3. Are you being blocked before WordPress?

If you are testing from the command line, check what actually came back before assuming it is the plugin:

```
curl -sI https://example.com/my-post.md
```

A `301` or `302` with a `Location` pointing at something like `/WAF-BLOCKED` is your firewall refusing the request, usually because `curl` is on a bad-bot list. It applies to the whole site — the HTML page is blocked identically. Re-test with a browser user agent:

```
curl -sI -A 'Mozilla/5.0' https://example.com/my-post.md
```

Some managed firewalls block specific AI crawlers by name as well. That is a policy decision on your host, separate from anything the plugin does.

## 4. Do you have pretty permalinks?

With plain permalinks (`?p=123`) there is no path to append `.md` to. The plugin falls back to `?format=markdown` on the permalink and the settings page says so. Switch to a pretty structure under **Settings → Permalinks** for clean `.md` URLs.

## 5. Is another plugin answering first?

The endpoint runs early on `template_redirect`, but a redirect manager or a security plugin can act sooner and send a redirect before the plugin sees the request. If `.md` URLs redirect somewhere unexpected, test with other plugins deactivated to find which one owns the rule.

For `/llms.txt` specifically, a real file in the site root always wins: the web server delivers it without ever starting WordPress. The settings page flags this when it detects one.

## Still nothing?

If the URL returns HTML rather than 404, the problem is different and usually a cache — see [Markdown negotiation returns HTML](/troubleshooting/negotiation-returns-html/).

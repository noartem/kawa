import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { renderMarkdown } from './markdown.ts';

describe('renderMarkdown', () => {
    it('returns an empty string for blank input', () => {
        assert.equal(renderMarkdown('   '), '');
    });

    it('renders common markdown formatting through unified', () => {
        const rendered = renderMarkdown(
            '# Title\n\n**Bold** _italic_ ~~gone~~\n\n- one\n- two\n\n> quoted',
        );

        assert.match(rendered, /<h1>Title<\/h1>/);
        assert.match(rendered, /<strong>Bold<\/strong>/);
        assert.match(rendered, /<em>italic<\/em>/);
        assert.match(rendered, /<del>gone<\/del>/);
        assert.match(rendered, /<ul>\s*<li>one<\/li>\s*<li>two<\/li>\s*<\/ul>/);
        assert.match(rendered, /<blockquote>\s*<p>quoted<\/p>\s*<\/blockquote>/);
    });

    it('preserves single line breaks inside paragraphs', () => {
        const rendered = renderMarkdown('first line\nsecond line');

        assert.match(rendered, /<p>first line<br>\s*second line<\/p>/);
    });

    it('keeps fenced code blocks escaped with language classes', () => {
        const rendered = renderMarkdown(
            '```python\nprint("<unsafe>")\n```',
        );

        assert.match(rendered, /<pre><code class="language-python">/);
        assert.match(rendered, /print\("&#x3C;unsafe>"\)/);
        assert.doesNotMatch(rendered, /<unsafe>/);
    });

    it('sanitizes unsafe html and links while preserving safe anchors', () => {
        const rendered = renderMarkdown(
            '<script>alert(1)<\/script>\n\n[Docs](https://example.com) [Bad](javascript:alert(1))',
        );

        assert.doesNotMatch(rendered, /<script>/);
        assert.doesNotMatch(rendered, /javascript:alert/);
        assert.match(
            rendered,
            /<a href="https:\/\/example\.com" rel="noreferrer noopener" target="_blank">Docs<\/a>/,
        );
    });

    it('strips markdown images to avoid remote resource loading', () => {
        const rendered = renderMarkdown('![tracker](https://example.com/pixel.png)');

        assert.doesNotMatch(rendered, /<img/);
        assert.doesNotMatch(rendered, /pixel\.png/);
    });
});

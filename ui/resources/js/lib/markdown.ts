const CODE_BLOCK_PLACEHOLDER = '\u0000CODE_BLOCK_';

const escapeHtml = (value: string): string => {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
};

const escapeAttribute = (value: string): string => {
    return escapeHtml(value);
};

const applyInlineMarkdown = (value: string): string => {
    let rendered = escapeHtml(value);

    rendered = rendered.replace(
        /\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/g,
        (_, label: string, href: string) => {
            return `<a href="${escapeAttribute(href)}" target="_blank" rel="noreferrer noopener">${label}</a>`;
        },
    );

    rendered = rendered.replace(/`([^`]+)`/g, '<code>$1</code>');
    rendered = rendered.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    rendered = rendered.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    rendered = rendered.replace(/~~([^~]+)~~/g, '<del>$1</del>');
    rendered = rendered.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    rendered = rendered.replace(/_([^_]+)_/g, '<em>$1</em>');

    return rendered;
};

const renderParagraph = (lines: string[]): string => {
    return `<p>${lines.map((line) => applyInlineMarkdown(line)).join('<br />')}</p>`;
};

const renderList = (lines: string[], ordered: boolean): string => {
    const tag = ordered ? 'ol' : 'ul';
    const items = lines
        .map((line) => {
            const content = ordered
                ? line.replace(/^\d+\.\s+/, '')
                : line.replace(/^[-*+]\s+/, '');

            return `<li>${applyInlineMarkdown(content)}</li>`;
        })
        .join('');

    return `<${tag}>${items}</${tag}>`;
};

const renderBlockquote = (lines: string[]): string => {
    return `<blockquote>${lines.map((line) => applyInlineMarkdown(line.replace(/^>\s?/, ''))).join('<br />')}</blockquote>`;
};

const renderHeading = (line: string): string => {
    const match = line.match(/^(#{1,6})\s+(.*)$/);

    if (match === null) {
        return renderParagraph([line]);
    }

    const level = match[1].length;

    return `<h${level}>${applyInlineMarkdown(match[2])}</h${level}>`;
};

export const renderMarkdown = (value: string): string => {
    if (value.trim() === '') {
        return '';
    }

    const codeBlocks: string[] = [];
    const normalizedValue = value.replaceAll('\r\n', '\n');
    const withoutCodeBlocks = normalizedValue.replace(
        /```([\w-]+)?\n([\s\S]*?)```/g,
        (_, language: string | undefined, code: string) => {
            const placeholder = `${CODE_BLOCK_PLACEHOLDER}${codeBlocks.length}\u0000`;
            const languageClass = language
                ? ` class="language-${escapeAttribute(language)}"`
                : '';

            codeBlocks.push(
                `<pre><code${languageClass}>${escapeHtml(code.trimEnd())}</code></pre>`,
            );

            return placeholder;
        },
    );

    const lines = withoutCodeBlocks.split('\n');
    const blocks: string[] = [];
    let paragraphLines: string[] = [];
    let listLines: string[] = [];
    let listOrdered = false;
    let quoteLines: string[] = [];

    const flushParagraph = (): void => {
        if (paragraphLines.length > 0) {
            blocks.push(renderParagraph(paragraphLines));
            paragraphLines = [];
        }
    };

    const flushList = (): void => {
        if (listLines.length > 0) {
            blocks.push(renderList(listLines, listOrdered));
            listLines = [];
        }
    };

    const flushQuote = (): void => {
        if (quoteLines.length > 0) {
            blocks.push(renderBlockquote(quoteLines));
            quoteLines = [];
        }
    };

    for (const line of lines) {
        if (line.startsWith(CODE_BLOCK_PLACEHOLDER)) {
            flushParagraph();
            flushList();
            flushQuote();

            const index = Number.parseInt(
                line.replace(CODE_BLOCK_PLACEHOLDER, '').replace('\u0000', ''),
                10,
            );

            if (Number.isInteger(index) && codeBlocks[index] !== undefined) {
                blocks.push(codeBlocks[index]);
            }

            continue;
        }

        if (line.trim() === '') {
            flushParagraph();
            flushList();
            flushQuote();
            continue;
        }

        if (/^#{1,6}\s+/.test(line)) {
            flushParagraph();
            flushList();
            flushQuote();
            blocks.push(renderHeading(line));
            continue;
        }

        if (/^>\s?/.test(line)) {
            flushParagraph();
            flushList();
            quoteLines.push(line);
            continue;
        }

        if (/^\d+\.\s+/.test(line)) {
            flushParagraph();
            flushQuote();

            if (!listOrdered && listLines.length > 0) {
                flushList();
            }

            listOrdered = true;
            listLines.push(line);
            continue;
        }

        if (/^[-*+]\s+/.test(line)) {
            flushParagraph();
            flushQuote();

            if (listOrdered && listLines.length > 0) {
                flushList();
            }

            listOrdered = false;
            listLines.push(line);
            continue;
        }

        flushList();
        flushQuote();
        paragraphLines.push(line);
    }

    flushParagraph();
    flushList();
    flushQuote();

    return blocks.join('');
};

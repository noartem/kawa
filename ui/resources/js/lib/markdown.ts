import type { Schema } from 'hast-util-sanitize';
import rehypeExternalLinks from 'rehype-external-links';
import rehypeSanitize, { defaultSchema } from 'rehype-sanitize';
import rehypeStringify from 'rehype-stringify';
import remarkBreaks from 'remark-breaks';
import remarkGfm from 'remark-gfm';
import remarkParse from 'remark-parse';
import remarkRehype from 'remark-rehype';
import { unified } from 'unified';

const markdownSchema: Schema = {
    ...defaultSchema,
    tagNames: (defaultSchema.tagNames ?? []).filter(
        (tagName) => !['img', 'picture', 'source'].includes(tagName),
    ),
    attributes: {
        ...defaultSchema.attributes,
        a: [
            ...(defaultSchema.attributes?.a ?? []),
            ['target', '_blank'],
            ['rel', 'noreferrer', 'noopener'],
        ],
        code: [
            ...(defaultSchema.attributes?.code ?? []),
            ['className', /^language-[\w-]+$/],
        ],
    },
};

const markdownProcessor = unified()
    .use(remarkParse)
    .use(remarkBreaks)
    .use(remarkGfm)
    .use(remarkRehype)
    .use(rehypeSanitize, markdownSchema)
    .use(rehypeExternalLinks, {
        target: '_blank',
        rel: ['noreferrer', 'noopener'],
    })
    .use(rehypeStringify);

export const renderMarkdown = (value: string): string => {
    if (value.trim() === '') {
        return '';
    }

    return String(markdownProcessor.processSync(value.replaceAll('\r\n', '\n')));
};

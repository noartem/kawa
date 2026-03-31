import { python } from '@codemirror/lang-python';
import { json } from '@codemirror/lang-json';
import type { Extension } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { githubDark, githubLight } from '@uiw/codemirror-theme-github';

interface CreateFlowCodeEditorExtensionsOptions {
    language?: 'python' | 'json' | 'text';
    bottomPadding?: string;
    lineWrapping?: boolean;
    isDarkTheme?: boolean;
    extraExtensions?: Extension[];
}

const pythonExtension = python();
const jsonExtension = json();

export const createFlowCodeEditorExtensions = (
    options: CreateFlowCodeEditorExtensionsOptions = {},
): Extension[] => {
    const {
        language = 'python',
        bottomPadding = '0.75rem',
        lineWrapping = true,
        isDarkTheme = false,
        extraExtensions = [],
    } = options;
    const githubTheme = isDarkTheme ? githubDark : githubLight;

    const codeEditorTheme = EditorView.theme({
        '&': {
            height: '100%',
        },
        '.cm-editor': {
            borderRadius: 'inherit',
            overflow: 'hidden',
        },
        '.cm-scroller': {
            overflow: 'auto',
            fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
            lineHeight: '1.5',
            borderRadius: 'inherit',
            borderTopRightRadius: 'inherit',
            borderBottomRightRadius: 'inherit',
        },
        '.cm-content': {
            minHeight: '100%',
            paddingTop: '0.5rem',
            paddingBottom: bottomPadding,
        },
        '.cm-gutters': {
            backgroundColor: 'var(--muted)',
            color: 'var(--muted-foreground)',
            borderRight: '1px solid var(--border)',
            borderTopLeftRadius: 'inherit',
            borderBottomLeftRadius: 'inherit',
        },
        '.cm-lineNumbers .cm-gutterElement': {
            padding: '0 0.6rem 0 0.75rem',
        },
        '&.cm-focused': {
            outline: 'none',
        },
    });

    const extensions: Extension[] = [
        githubTheme,
        codeEditorTheme,
        ...extraExtensions,
    ];

    if (language === 'python') {
        extensions.unshift(pythonExtension);
    }

    if (language === 'json') {
        extensions.unshift(jsonExtension);
    }

    if (lineWrapping) {
        extensions.push(EditorView.lineWrapping);
    }

    return extensions;
};

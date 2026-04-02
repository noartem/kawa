import { json } from '@codemirror/lang-json';
import { python } from '@codemirror/lang-python';
import type { Extension } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { githubDark, githubLight } from '@uiw/codemirror-theme-github';

interface CreateFlowCodeEditorExtensionsOptions {
    language?: 'python' | 'json' | 'text';
    bottomPadding?: string;
    lineWrapping?: boolean;
    isDarkTheme?: boolean;
    containOverscroll?: boolean;
    inheritBorderRadius?: boolean;
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
        containOverscroll = false,
        inheritBorderRadius = true,
        extraExtensions = [],
    } = options;
    const githubTheme = isDarkTheme ? githubDark : githubLight;

    const codeEditorTheme = EditorView.theme({
        '&': {
            height: '100%',
            ...(containOverscroll ? { overscrollBehavior: 'contain' } : {}),
        },
        '.cm-editor': {
            borderRadius: inheritBorderRadius ? 'inherit' : '0',
            overflow: 'hidden',
            ...(containOverscroll ? { overscrollBehavior: 'contain' } : {}),
        },
        '.cm-scroller': {
            overflow: 'auto',
            ...(containOverscroll
                ? {
                      overscrollBehavior: 'contain',
                      overscrollBehaviorX: 'contain',
                      overscrollBehaviorY: 'contain',
                  }
                : {}),
            fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
            lineHeight: '1.5',
            borderRadius: inheritBorderRadius ? 'inherit' : '0',
            borderTopRightRadius: inheritBorderRadius ? 'inherit' : '0',
            borderBottomRightRadius: inheritBorderRadius ? 'inherit' : '0',
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
            borderTopLeftRadius: inheritBorderRadius ? 'inherit' : '0',
            borderBottomLeftRadius: inheritBorderRadius ? 'inherit' : '0',
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

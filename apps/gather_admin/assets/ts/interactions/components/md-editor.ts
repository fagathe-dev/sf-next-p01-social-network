// =============================================================================
// md-editor.ts — Éditeur Markdown ([data-ds-md-editor])
// -----------------------------------------------------------------------------
// - Mode Écrire / Aperçu : SegmentedControl [data-md-mode] (options "write" et
//   "preview") ; l'aperçu est généré par convertMarkdownToHtml (core-ts).
// - Boutons [data-md-action] : entourent la sélection (gras, lien…) ou
//   préfixent les lignes sélectionnées (titre, citation, listes). Un second
//   clic retire la mise en forme.
// - Raccourcis dans la saisie : Ctrl/⌘ + B, I, K, E.
// - Entrée dans une liste ou une citation : continue la liste, ou la termine
//   si l'élément est vide.
// Les modifications passent par l'historique natif (Ctrl+Z fonctionne).
// =============================================================================

import { convertMarkdownToHtml } from 'core-ts';
import { getSegmentValue } from './segmented-control';
import type { SegmentedChangeDetail } from './segmented-control';

type EditorMode = 'write' | 'preview';

interface Editor {
  root: HTMLElement;
  textarea: HTMLTextAreaElement;
  preview: HTMLElement;
  buttons: HTMLButtonElement[];
}

interface WrapAction {
  before: string;
  after: string;
  placeholder: string;
}

interface LineAction {
  prefix: (index: number) => string;
  // Préfixe déjà présent (pour retirer la mise en forme)
  pattern: RegExp;
  // Remplace un titre ou une liste existants au lieu de s'y ajouter
  exclusive: boolean;
}

// Actions qui entourent la sélection
const WRAP_ACTIONS: Record<string, WrapAction> = {
  bold: { before: '**', after: '**', placeholder: 'texte en gras' },
  italic: { before: '_', after: '_', placeholder: 'texte en italique' },
  strike: { before: '~~', after: '~~', placeholder: 'texte barré' },
  highlight: { before: '==', after: '==', placeholder: 'texte surligné' },
  code: { before: '`', after: '`', placeholder: 'code' },
  codeblock: { before: '```\n', after: '\n```', placeholder: 'code' },
  link: { before: '[', after: '](https://)', placeholder: 'texte du lien' },
};

const LIST_PATTERN = /^([-*+]|\d+\.) (\[[ xX]\] )?/;

// Actions qui préfixent chaque ligne sélectionnée
const LINE_ACTIONS: Record<string, LineAction> = {
  heading: { prefix: () => '## ', pattern: /^#{1,6} /, exclusive: true },
  quote: { prefix: () => '> ', pattern: /^> /, exclusive: false },
  ul: { prefix: () => '- ', pattern: /^[-*+] (?!\[[ xX]\] )/, exclusive: true },
  ol: { prefix: (index) => `${index + 1}. `, pattern: /^\d+\. /, exclusive: true },
  task: { prefix: () => '- [ ] ', pattern: /^[-*+] \[[ xX]\] /, exclusive: true },
};

const SHORTCUTS: Record<string, string> = {
  b: 'bold',
  i: 'italic',
  k: 'link',
  e: 'code',
};

// Remplace une plage de texte. execCommand conserve l'historique d'annulation
// et émet l'événement input ; setRangeText sert de repli.
const replaceRange = (
  textarea: HTMLTextAreaElement,
  start: number,
  end: number,
  text: string,
): void => {
  textarea.focus();
  textarea.setSelectionRange(start, end);
  if (!document.execCommand('insertText', false, text)) {
    textarea.setRangeText(text, start, end, 'end');
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }
};

const applyWrap = (
  textarea: HTMLTextAreaElement,
  name: string,
  { before, after, placeholder }: WrapAction,
): void => {
  const { selectionStart: start, selectionEnd: end, value } = textarea;
  const selected = value.slice(start, end);

  // Déjà entouré : on retire la mise en forme (sauf pour un lien)
  if (
    name !== 'link' &&
    value.slice(start - before.length, start) === before &&
    value.slice(end, end + after.length) === after
  ) {
    replaceRange(textarea, start - before.length, end + after.length, selected);
    textarea.setSelectionRange(start - before.length, end - before.length);
    return;
  }

  const text = selected || placeholder;
  replaceRange(textarea, start, end, `${before}${text}${after}`);

  const textStart = start + before.length;
  if (name === 'link' && selected) {
    // Le texte est déjà là : on sélectionne l'URL à saisir
    const urlStart = textStart + text.length + 2;
    textarea.setSelectionRange(urlStart, urlStart + 'https://'.length);
  } else {
    textarea.setSelectionRange(textStart, textStart + text.length);
  }
};

const applyLinePrefix = (
  textarea: HTMLTextAreaElement,
  { prefix, pattern, exclusive }: LineAction,
): void => {
  const { selectionStart, selectionEnd, value } = textarea;
  const lineStart = value.lastIndexOf('\n', selectionStart - 1) + 1;
  // Une sélection qui se termine en début de ligne n'inclut pas cette ligne
  const endIndex =
    selectionEnd > selectionStart && value[selectionEnd - 1] === '\n'
      ? selectionEnd - 1
      : selectionEnd;
  const nextBreak = value.indexOf('\n', endIndex);
  const lineEnd = nextBreak === -1 ? value.length : nextBreak;

  const lines = value.slice(lineStart, lineEnd).split('\n');
  const filled = lines.filter((line) => line.trim() !== '');
  const remove = filled.length > 0 && filled.every((line) => pattern.test(line));

  let index = 0;
  const updated = lines
    .map((line) => {
      if (remove) return line.replace(pattern, '');
      if (line.trim() === '' && lines.length > 1) return line;
      // Remplace un autre type de liste ou de titre au lieu de cumuler
      const bare = exclusive
        ? line.replace(LIST_PATTERN, '').replace(/^#{1,6} /, '')
        : line;
      return `${prefix(index++)}${bare}`;
    })
    .join('\n');

  replaceRange(textarea, lineStart, lineEnd, updated);
  if (lines.length > 1) {
    textarea.setSelectionRange(lineStart, lineStart + updated.length);
  }
};

const applyAction = (textarea: HTMLTextAreaElement, name: string): void => {
  if (WRAP_ACTIONS[name]) applyWrap(textarea, name, WRAP_ACTIONS[name]);
  else if (LINE_ACTIONS[name]) applyLinePrefix(textarea, LINE_ACTIONS[name]);
};

// Entrée en fin d'élément de liste ou de citation : continue ou termine
const continueBlock = (
  textarea: HTMLTextAreaElement,
  event: KeyboardEvent,
): void => {
  const { selectionStart: position, selectionEnd, value } = textarea;
  if (position !== selectionEnd) return;

  const lineStart = value.lastIndexOf('\n', position - 1) + 1;
  const line = value.slice(lineStart, position);
  const match = /^(\s*)(?:([-*+]) (\[[ xX]\] )?|(\d+)\. |> )/.exec(line);
  if (!match) return;

  event.preventDefault();

  // Élément vide : on termine la liste en retirant son préfixe
  if (line.trim() === match[0].trim()) {
    replaceRange(textarea, lineStart, position, '');
    return;
  }

  const [, indent, bullet, task, number] = match;
  let prefix = `${indent}> `;
  if (number) prefix = `${indent}${Number(number) + 1}. `;
  else if (bullet) prefix = `${indent}${bullet} ${task ? '[ ] ' : ''}`;

  replaceRange(textarea, position, position, `\n${prefix}`);
};

const handleKeydown = (editor: Editor, event: KeyboardEvent): void => {
  if (event.isComposing) return;

  const action = SHORTCUTS[event.key.toLowerCase()];
  if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && action) {
    event.preventDefault();
    applyAction(editor.textarea, action);
    return;
  }

  if (event.key === 'Enter' && !event.shiftKey) {
    continueBlock(editor.textarea, event);
  }
};

const renderPreview = (markdown: string): string =>
  markdown.trim()
    ? convertMarkdownToHtml(markdown)
    : '<p class="md-editor-empty">Rien à prévisualiser.</p>';

const setMode = (editor: Editor, mode: EditorMode): void => {
  const isPreview = mode === 'preview';

  if (isPreview) {
    // Même hauteur que la saisie : le passage d'un mode à l'autre ne saute pas
    editor.preview.style.minBlockSize = `${editor.textarea.offsetHeight}px`;
    editor.preview.innerHTML = renderPreview(editor.textarea.value);
  }

  editor.textarea.hidden = isPreview;
  editor.preview.hidden = !isPreview;
  editor.buttons.forEach((button) => {
    button.disabled = isPreview;
  });
  editor.root.dataset.mode = mode;

  if (!isPreview) editor.textarea.focus();
};

const initEditor = (root: HTMLElement): void => {
  const textarea = root.querySelector<HTMLTextAreaElement>(':scope > textarea');
  if (!textarea || root.dataset.mdReady) return;

  let preview = root.querySelector<HTMLElement>(':scope > .md-editor-preview');
  if (!preview) {
    preview = document.createElement('div');
    preview.className = 'md-editor-preview';
    preview.hidden = true;
    textarea.insertAdjacentElement('afterend', preview);
  }

  const editor: Editor = {
    root,
    textarea,
    preview,
    buttons: Array.from(
      root.querySelectorAll<HTMLButtonElement>('[data-md-action]'),
    ),
  };

  editor.buttons.forEach((button) => {
    button.addEventListener('click', () => {
      if (button.dataset.mdAction) applyAction(textarea, button.dataset.mdAction);
    });
  });

  textarea.addEventListener('keydown', (event) => handleKeydown(editor, event));

  const modeControl = root.querySelector<HTMLElement>('[data-md-mode]');
  modeControl?.addEventListener('ds:change', (event) => {
    const { value } = (event as CustomEvent<SegmentedChangeDetail>).detail;
    setMode(editor, value === 'preview' ? 'preview' : 'write');
  });

  // Éditeur livré en mode Aperçu : on génère l'aperçu tout de suite
  if (modeControl && getSegmentValue(modeControl) === 'preview') {
    setMode(editor, 'preview');
  }

  root.dataset.mdReady = 'true';
};

/** Active les éditeurs [data-ds-md-editor] présents dans `root`. */
export const initMarkdownEditors = (root: ParentNode = document): void => {
  root
    .querySelectorAll<HTMLElement>('[data-ds-md-editor]')
    .forEach((element) => initEditor(element));
};

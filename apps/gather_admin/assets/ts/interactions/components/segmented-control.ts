// =============================================================================
// segmented-control.ts — Choix exclusif dans un .segmented-control
// -----------------------------------------------------------------------------
// Balisage : .segmented-control[role="radiogroup"][aria-label]
//              > button.segmented-control-option[role="radio"][data-value][aria-checked]
// - Clic : sélectionne l'option.
// - Flèches, Début, Fin : déplacent la sélection et le focus.
// - Tabulation itinérante : seule l'option sélectionnée est atteignable avec Tab.
// - Émet `ds:change` sur le groupe à chaque changement : event.detail.value.
// =============================================================================

export interface SegmentedChangeDetail {
  value: string;
}

const GROUP_SELECTOR = '.segmented-control';
const OPTION_SELECTOR = '.segmented-control-option[role="radio"]';

let listening = false;

const getOptions = (group: HTMLElement): HTMLButtonElement[] =>
  Array.from(
    group.querySelectorAll<HTMLButtonElement>(`:scope > ${OPTION_SELECTOR}`),
  );

const getEnabledOptions = (group: HTMLElement): HTMLButtonElement[] =>
  getOptions(group).filter((option) => !option.disabled);

// Seule l'option sélectionnée (ou la première disponible) entre dans l'ordre de tabulation
const syncTabIndex = (group: HTMLElement): void => {
  const options = getOptions(group);
  const enabled = options.filter((option) => !option.disabled);
  const tabbable =
    enabled.find((option) => option.getAttribute('aria-checked') === 'true') ??
    enabled[0];

  options.forEach((option) => {
    option.tabIndex = option === tabbable ? 0 : -1;
  });
};

/** Valeur de l'option sélectionnée. */
export const getSegmentValue = (group: HTMLElement): string | undefined =>
  getOptions(group).find(
    (option) => option.getAttribute('aria-checked') === 'true',
  )?.dataset.value;

/** Sélectionne l'option `value` et émet `ds:change` si la sélection change. */
export const selectSegment = (
  group: HTMLElement,
  value: string,
  { focus = false, silent = false } = {},
): void => {
  const options = getOptions(group);
  const target = options.find(
    (option) => option.dataset.value === value && !option.disabled,
  );
  if (!target) return;

  const changed = target.getAttribute('aria-checked') !== 'true';
  options.forEach((option) =>
    option.setAttribute('aria-checked', String(option === target)),
  );
  syncTabIndex(group);
  if (focus) target.focus();

  if (changed && !silent) {
    group.dispatchEvent(
      new CustomEvent<SegmentedChangeDetail>('ds:change', {
        bubbles: true,
        detail: { value },
      }),
    );
  }
};

const handleClick = (event: MouseEvent): void => {
  const option = (event.target as Element).closest<HTMLButtonElement>(
    OPTION_SELECTOR,
  );
  const group = option?.parentElement;
  if (!option || !group?.matches(GROUP_SELECTOR) || option.disabled) return;
  if (option.dataset.value) selectSegment(group, option.dataset.value);
};

const handleKeydown = (event: KeyboardEvent): void => {
  const option = (event.target as Element).closest<HTMLButtonElement>(
    OPTION_SELECTOR,
  );
  const group = option?.parentElement;
  if (!option || !group?.matches(GROUP_SELECTOR)) return;

  const options = getEnabledOptions(group);
  const index = options.indexOf(option);
  const last = options.length - 1;
  let next: number;

  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      next = index >= last ? 0 : index + 1;
      break;
    case 'ArrowLeft':
    case 'ArrowUp':
      next = index <= 0 ? last : index - 1;
      break;
    case 'Home':
      next = 0;
      break;
    case 'End':
      next = last;
      break;
    default:
      return;
  }

  event.preventDefault();
  const value = options[next]?.dataset.value;
  if (value) selectSegment(group, value, { focus: true });
};

/**
 * Prépare les contrôles présents dans `root` (tabulation) et pose, une seule
 * fois, les listeners délégués valables aussi pour les contrôles ajoutés ensuite.
 */
export const initSegmentedControls = (root: ParentNode = document): void => {
  root
    .querySelectorAll<HTMLElement>(GROUP_SELECTOR)
    .forEach((group) => syncTabIndex(group));

  if (listening) return;
  listening = true;
  document.addEventListener('click', handleClick);
  document.addEventListener('keydown', handleKeydown);
};

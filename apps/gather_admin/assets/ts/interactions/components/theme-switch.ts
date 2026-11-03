// =============================================================================
// theme-switch.ts — Relie les SegmentedControl [data-ds-theme-switch] au
// service de thème (options data-value : auto, light, dark).
// =============================================================================

import { isThemeMode, themeService } from '@/services/theme';
import { selectSegment } from './segmented-control';
import type { SegmentedChangeDetail } from './segmented-control';

const SWITCH_SELECTOR = '[data-ds-theme-switch]';

let listening = false;

// Reflète la préférence courante sur tous les sélecteurs (sans réémettre)
const syncSwitches = (): void => {
  document
    .querySelectorAll<HTMLElement>(SWITCH_SELECTOR)
    .forEach((element) =>
      selectSegment(element, themeService.getMode(), { silent: true }),
    );
};

/**
 * Synchronise les sélecteurs présents et pose, une seule fois, l'écoute
 * déléguée de `ds:change` (valable aussi pour les sélecteurs ajoutés ensuite).
 */
export const initThemeSwitches = (): void => {
  syncSwitches();

  if (listening) return;
  listening = true;

  document.addEventListener('ds:change', (event) => {
    const target = event.target as Element;
    if (!target.matches(SWITCH_SELECTOR)) return;
    const { value } = (event as CustomEvent<SegmentedChangeDetail>).detail;
    if (isThemeMode(value)) themeService.setMode(value);
  });

  // Choix fait ailleurs (autre sélecteur, autre onglet) : on aligne tout le monde
  themeService.subscribe(syncSwitches);
};

// =============================================================================
// main.ts — Orchestrateur : initialisation des interactions globales
// =============================================================================

import {
  handleToggle,
  handleBackdropClick,
  closeAllDropdowns,
} from './interactions/events/toggle';
import { handleDismiss, handleEscapeKey } from './interactions/events/dismiss';
import { initSegmentedControls } from './interactions/components/segmented-control';
import { initMarkdownEditors } from './interactions/components/md-editor';
import { initThemeSwitches } from './interactions/components/theme-switch';

// Import de ta librairie
import { initClipboardHandlers, convertMarkdownToHtml } from 'core-ts';

const initUI = (): void => {
  // 1. Interactions globales du DOM (Bascule, Fermeture, etc.)
  document.addEventListener('click', (event: MouseEvent) => {
    const target = event.target as Element;

    // Fermetures explicites
    const dismissTrigger = target.closest<HTMLElement>('[data-ds-dismiss]');
    if (dismissTrigger) {
      handleDismiss(dismissTrigger);
      return;
    }

    // Ouvertures / Bascules
    const toggleTrigger = target.closest<HTMLElement>('[data-ds-toggle]');
    if (toggleTrigger) {
      handleToggle(toggleTrigger, event);
    }

    // Fermeture des Dropdowns via clic extérieur
    if (!target.closest('.dropdown') && !toggleTrigger) {
      closeAllDropdowns();
    }

    // Fermeture des Modales via clic sur le Backdrop
    if (target instanceof HTMLDialogElement) {
      handleBackdropClick(event, target);
    }
  });

  // Gestion de la touche Escape pour les popups
  document.addEventListener('keydown', handleEscapeKey);

  // 2. Initialisation globale du Presse-papier
  // Rend fonctionnels tous les boutons avec l'attribut data-copy-target
  initClipboardHandlers();

  // 3. Initialisation globale du Markdown
  // Convertit à la volée le contenu des éléments marqués avec data-markdown
  document.querySelectorAll<HTMLElement>('[data-markdown]').forEach((el) => {
    // textContent permet de récupérer le contenu brut de la balise
    const rawContent = el.textContent || '';
    el.innerHTML = convertMarkdownToHtml(rawContent);
  });

  // 4. Composants interactifs : SegmentedControl puis ceux qui écoutent son
  // événement ds:change (sélecteurs de thème, éditeurs Markdown)
  initSegmentedControls();
  initThemeSwitches();
  initMarkdownEditors();
};

document.addEventListener('DOMContentLoaded', initUI);

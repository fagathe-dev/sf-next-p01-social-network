import { closeDialog } from '@/interactions/components/modal';
import { closeAllDropdowns, findSidebarOverlay } from '@/interactions/events/toggle';
import { $ } from 'core-ts';

export const handleDismiss = (trigger: HTMLElement): void => {
  const dismissType = trigger.getAttribute('data-ds-dismiss');

  if (dismissType === 'modal' || dismissType === 'dialog') {
    const dialog = trigger.closest('dialog') as HTMLDialogElement | null;
    if (dialog) closeDialog(dialog);
    return;
  }

  if (dismissType === 'alert' || dismissType === 'toast') {
    const target = trigger.closest(`.${dismissType}`) as HTMLElement | null;
    if (target) {
      target.classList.add('hide');
      setTimeout(() => target.remove(), 300);
    }
    return;
  }

  if (dismissType === 'sidebar') {
    const selector =
      trigger.getAttribute('data-ds-target') || '.dashboard-sidebar';
    const sidebar = $(selector) as HTMLElement | null;
    const overlay = findSidebarOverlay(selector);

    if (sidebar) sidebar.classList.remove('is-open');
    if (overlay) overlay.classList.remove('is-visible');

    ($('[data-ds-toggle="sidebar"]', true) as NodeListOf<HTMLElement>).forEach(
      (t) => t.setAttribute('aria-expanded', 'false'),
    );
  }
};

export const handleEscapeKey = (event: KeyboardEvent): void => {
  if (event.key !== 'Escape') return;

  // Menu déroulant ouvert : on le ferme et on rend le focus à son déclencheur
  const openDropdown = document.querySelector<HTMLElement>(
    '[data-ds-toggle="dropdown"][aria-expanded="true"]',
  );
  if (openDropdown) {
    closeAllDropdowns();
    openDropdown.focus();
  }

  (
    $<HTMLDialogElement>('dialog[open]', true) as NodeListOf<HTMLDialogElement>
  ).forEach((d) => closeDialog(d));
};

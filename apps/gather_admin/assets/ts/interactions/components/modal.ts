// modal.ts

export type DialogToggle = 'modal' | 'dialog';

export const openDialog = (
  dialog: HTMLDialogElement,
  toggle: DialogToggle,
): void => {
  if (toggle === 'modal') {
    dialog.showModal();
  } else {
    dialog.show();
  }
  dialog.dispatchEvent(new CustomEvent('ds:open', { bubbles: true }));
};

export const closeDialog = (dialog: HTMLDialogElement): void => {
  dialog.close();
  dialog.dispatchEvent(new CustomEvent('ds:close', { bubbles: true }));
};

import { createElement, insertElementToDOM, $ } from 'core-ts';

export type ToastType = 'info' | 'success' | 'warning' | 'error';

export class Toast {
  constructor(
    message: string,
    type: ToastType = 'info',
    duration: number = 3000,
  ) {
    this.show(message, type, duration);
  }

  private show(message: string, type: ToastType, duration: number): void {
    const container =
      $<HTMLElement>('#toast-container') || this.createContainer();
    const toast = createElement('div', `toast toast-${type}`, '', {
      role: 'alert',
      'aria-live': 'assertive',
      'aria-atomic': 'true',
    });

    toast.innerHTML = `
      <i class="ri-${this.getIcon(type)}-fill toast-icon"></i>
      <div class="toast-body">${message}</div>
      <button type="button" class="btn-close" data-ds-dismiss="toast" aria-label="Fermer"></button>
    `;

    insertElementToDOM(toast, 'beforeend', container as HTMLElement);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => this.dismiss(toast), duration);
  }

  private createContainer(): HTMLElement {
    const container = createElement('div', '', 'toast-container');
    insertElementToDOM(container, 'beforeend', document.body);
    return container;
  }

  private dismiss(toast: HTMLElement): void {
    toast.classList.remove('show');
    toast.classList.add('hide');
    setTimeout(() => toast.remove(), 300);
  }

  private getIcon(type: ToastType): string {
    const icons: Record<ToastType, string> = {
      info: 'information',
      success: 'checkbox-circle',
      warning: 'alert',
      error: 'error-warning',
    };
    return icons[type];
  }
}

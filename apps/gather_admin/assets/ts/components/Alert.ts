import { createElement, insertElementToDOM } from 'core-ts';

export type AlertType = 'info' | 'success' | 'warning' | 'danger';

export class Alert {
  private element: HTMLElement;

  constructor(
    content: string,
    type: AlertType = 'info',
    root: HTMLElement = document.body
  ) {
    this.element = this.createAlert(content, type);
    insertElementToDOM(this.element, 'beforeend', root);
  }

  private createAlert(content: string, type: AlertType): HTMLElement {
    const alert = createElement('div', `alert alert-soft-${type} alert-dismissible`, '', { role: 'alert' });
    alert.innerHTML = `
      <i class="ri-${this.getIcon(type)}-fill alert-icon"></i>
      <div class="alert-content">${content}</div>
      <button type="button" class="btn-close" data-ds-dismiss="alert" aria-label="Fermer"></button>
    `;
    return alert;
  }

  private getIcon(type: AlertType): string {
    const icons: Record<AlertType, string> = {
      info: 'information',
      success: 'checkbox-circle',
      warning: 'alert',
      danger: 'error-warning',
    };
    return icons[type];
  }
}
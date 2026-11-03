/**
 * Aperçu d'un e-mail : le segmented control fait basculer l'iframe entre les largeurs desktop et mobile.
 */
const device = document.querySelector<HTMLElement>('#preview-device');
const frame = document.querySelector<HTMLIFrameElement>('#preview');

device?.addEventListener('ds:change', (event) => {
  const { value } = (event as CustomEvent<{ value: string }>).detail;
  frame?.classList.toggle('is-mobile', value === 'mobile');
});

// =============================================================================
// services/theme.ts — Mode d'affichage : 'auto' | 'light' | 'dark'
// -----------------------------------------------------------------------------
// - Mémorise le choix dans localStorage (clé "ds-theme").
// - Applique le thème résolu sur <html data-ui-theme="light|dark"> (tokens
//   du thème sombre : foundation/_tokens.scss).
// - En mode 'auto', suit le réglage de l'appareil (prefers-color-scheme).
// - Synchronise les onglets ouverts et notifie les abonnés.
// Le script en ligne de base.html.twig applique le même thème avant le premier
// rendu (pas de flash) : la clé et les valeurs doivent rester identiques.
// =============================================================================

export type ThemeMode = 'auto' | 'light' | 'dark';
export type ResolvedTheme = 'light' | 'dark';

export interface ThemeState {
  mode: ThemeMode;
  theme: ResolvedTheme;
}

type ThemeListener = (state: ThemeState) => void;

export const THEME_STORAGE_KEY = 'ds-theme';
const THEME_ATTRIBUTE = 'data-ui-theme';
const MODES: readonly ThemeMode[] = ['auto', 'light', 'dark'];

export const isThemeMode = (value: unknown): value is ThemeMode =>
  MODES.includes(value as ThemeMode);

class ThemeService {
  private mode: ThemeMode;
  private readonly systemDark = window.matchMedia('(prefers-color-scheme: dark)');
  private readonly listeners = new Set<ThemeListener>();

  constructor() {
    this.mode = this.readStoredMode();
    this.apply();

    // En mode auto, le thème suit les changements de réglage de l'appareil
    this.systemDark.addEventListener('change', () => {
      if (this.mode === 'auto') this.apply();
    });

    // Choix fait dans un autre onglet
    window.addEventListener('storage', (event) => {
      if (event.key !== THEME_STORAGE_KEY) return;
      this.mode = isThemeMode(event.newValue) ? event.newValue : 'auto';
      this.apply();
    });
  }

  /** Préférence choisie : 'auto', 'light' ou 'dark'. */
  getMode(): ThemeMode {
    return this.mode;
  }

  /** Thème réellement affiché ('auto' résolu selon l'appareil). */
  getTheme(): ResolvedTheme {
    if (this.mode === 'auto') return this.systemDark.matches ? 'dark' : 'light';
    return this.mode;
  }

  getState(): ThemeState {
    return { mode: this.mode, theme: this.getTheme() };
  }

  /** Change et mémorise la préférence. */
  setMode(mode: ThemeMode): void {
    if (!isThemeMode(mode) || mode === this.mode) return;
    this.mode = mode;
    try {
      localStorage.setItem(THEME_STORAGE_KEY, mode);
    } catch {
      // Stockage indisponible (navigation privée…) : le choix vaut pour la page
    }
    this.apply();
  }

  /** Appelé à chaque changement ; retourne la fonction de désabonnement. */
  subscribe(listener: ThemeListener): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  private readStoredMode(): ThemeMode {
    try {
      const stored = localStorage.getItem(THEME_STORAGE_KEY);
      return isThemeMode(stored) ? stored : 'auto';
    } catch {
      return 'auto';
    }
  }

  private apply(): void {
    const state = this.getState();
    document.documentElement.setAttribute(THEME_ATTRIBUTE, state.theme);
    this.listeners.forEach((listener) => listener(state));
    document.dispatchEvent(
      new CustomEvent<ThemeState>('ds:theme-change', { detail: state }),
    );
  }
}

/** Instance unique, partagée par tous les scripts de la page. */
export const themeService = new ThemeService();

import type { ReactElement } from 'react';
import { t } from '@/lib/i18n';

export type Section = 'character' | 'adventure' | 'holding' | 'gear';

const SECTIONS: {
  key: Section;
  label: string;
  hint: string;
  glyph: (props: { className?: string }) => ReactElement;
}[] = [
  {
    key: 'character',
    label: 'nav.character',
    hint: 'nav.characterHint',
    glyph: (props) => (
      <svg {...props} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6">
        <circle cx="12" cy="8" r="3.4" />
        <path d="M5 20c1.2-4 4-6 7-6s5.8 2 7 6" />
      </svg>
    ),
  },
  {
    key: 'adventure',
    label: 'nav.adventure',
    hint: 'nav.adventureHint',
    glyph: (props) => (
      <svg {...props} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6">
        <path d="M6 3v18M6 3l11 5-11 5" />
      </svg>
    ),
  },
  {
    key: 'holding',
    label: 'nav.holding',
    hint: 'nav.holdingHint',
    glyph: (props) => (
      <svg {...props} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6">
        <path d="M4 11 12 4l8 7" />
        <path d="M6 10v10h12V10" />
        <path d="M10 20v-6h4v6" />
      </svg>
    ),
  },
  {
    key: 'gear',
    label: 'nav.gear',
    hint: 'nav.gearHint',
    glyph: (props) => (
      <svg {...props} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6">
        <path d="M12 3l2.1 3.2 3.7.7-2.6 2.8.5 3.8L12 12l-3.7 1.5.5-3.8-2.6-2.8 3.7-.7z" />
        <path d="M8 15l-3.2 3.2M16 15l3.2 3.2" />
      </svg>
    ),
  },
];

/**
 * The section rail. A left rail on wide viewports, a bottom bar on narrow
 * ones — same buttons, `flex-row`/`flex-col` swap on the `sm` breakpoint
 * rather than two separate components.
 */
export function Navigation({ section, onSelect }: { section: Section; onSelect: (section: Section) => void }) {
  return (
    <nav
      aria-label={t('nav.sections')}
      className="flex shrink-0 gap-1 border-ash-700 bg-ash-900 p-2 sm:w-48 sm:flex-col sm:border-r sm:p-3"
    >
      {SECTIONS.map(({ key, label, hint, glyph: Glyph }) => {
        const active = section === key;

        return (
          <button
            key={key}
            type="button"
            aria-current={active}
            onClick={() => onSelect(key)}
            className={`flex flex-1 items-center gap-3 rounded-md px-3 py-2 text-left transition-colors sm:flex-none ${
              active ? 'bg-ember-500/10 text-ember-400' : 'text-ash-200 hover:bg-ash-800 hover:text-ash-50'
            }`}
          >
            <Glyph className="hidden h-[18px] w-[18px] shrink-0 sm:block" />
            <span className="flex flex-col items-center sm:items-start">
              <span className="text-[13px] font-bold tracking-wide">{t(label)}</span>
              <span className="hidden text-[10.5px] text-ash-400 sm:block">{t(hint)}</span>
            </span>
          </button>
        );
      })}
    </nav>
  );
}

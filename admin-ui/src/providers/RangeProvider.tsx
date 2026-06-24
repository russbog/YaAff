import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';

export interface RangePreset {
  key: string;
  label: string;
  seconds: number;
}

// eslint-disable-next-line react-refresh/only-export-components
export const RANGE_PRESETS: RangePreset[] = [
  { key: '1h', label: '1H', seconds: 3600 },
  { key: '24h', label: '24H', seconds: 86400 },
  { key: '7d', label: '7D', seconds: 604800 },
  { key: '30d', label: '30D', seconds: 2592000 },
];

export interface CustomRange {
  start: number;
  end: number;
}

interface RangeCtx {
  preset: RangePreset;
  setPreset: (p: RangePreset) => void;
  custom: CustomRange | null;
  setCustom: (r: CustomRange | null) => void;
  bounds: { start: number; end: number };
  isCustom: boolean;
}

const Ctx = createContext<RangeCtx | null>(null);

export function RangeProvider({ children }: { children: ReactNode }) {
  const [preset, setPresetState] = useState<RangePreset>(RANGE_PRESETS[1]);
  const [custom, setCustom] = useState<CustomRange | null>(null);

  const setPreset = (p: RangePreset) => {
    setCustom(null);
    setPresetState(p);
  };

  const value = useMemo<RangeCtx>(() => {
    if (custom) {
      return { preset, setPreset, custom, setCustom, bounds: custom, isCustom: true };
    }
    const end = Math.floor(Date.now() / 1000);
    return {
      preset,
      setPreset,
      custom,
      setCustom,
      bounds: { start: end - preset.seconds, end },
      isCustom: false,
    };
  }, [preset, custom]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useRange(): RangeCtx {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useRange must be used within RangeProvider');
  return ctx;
}

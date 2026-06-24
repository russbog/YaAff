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

interface RangeCtx {
  preset: RangePreset;
  setPreset: (p: RangePreset) => void;
  bounds: { start: number; end: number };
}

const Ctx = createContext<RangeCtx | null>(null);

export function RangeProvider({ children }: { children: ReactNode }) {
  const [preset, setPreset] = useState<RangePreset>(RANGE_PRESETS[1]);
  const value = useMemo<RangeCtx>(() => {
    const end = Math.floor(Date.now() / 1000);
    return { preset, setPreset, bounds: { start: end - preset.seconds, end } };
  }, [preset]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useRange(): RangeCtx {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useRange must be used within RangeProvider');
  return ctx;
}

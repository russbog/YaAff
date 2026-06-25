import { useQuery } from '@tanstack/react-query';
import { Trash2, Plus, FileText, Target, Link2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input, Select } from '@/components/ui/Field';
import { entityApi } from '@/lib/api';
import type { EntityRecord } from '@/lib/types';
import { hostOf, type Step, type StepRedirectType } from '@/lib/campaign';
import { ChoiceRow, EntityPickerModal, FolderRow, Group } from './parts';
import { useState } from 'react';

const STEP_REDIRECT_TYPES: { value: string; label: string }[] = [
  { value: '301', label: 'HTTP 301' },
  { value: '302', label: 'HTTP 302' },
  { value: '303', label: 'HTTP 303' },
  { value: '307', label: 'HTTP 307' },
  { value: 'http_404', label: 'HTTP 404 (not found)' },
  { value: 'js', label: 'JS redirect' },
  { value: 'meta', label: 'Meta refresh' },
  { value: 'double_meta', label: 'Double meta (drop referrer)' },
  { value: 'blank_referrer', label: 'Blank referrer' },
  { value: 'formsubmit', label: 'Form submit (POST)' },
  { value: 'iframe', label: 'iframe' },
  { value: 'curl', label: 'cURL proxy' },
  { value: 'remote', label: 'Remote reverse-proxy' },
  { value: 'inline', label: 'Inline content' },
  { value: 'custom_json', label: 'Custom JSON' },
];

function redirectTypeValue(t: StepRedirectType): string {
  return String(t);
}
function parseRedirectType(v: string): StepRedirectType {
  return /^\d+$/.test(v) ? parseInt(v, 10) : v;
}

// UI-level step target. Maps to backend (action, landings[], offers[], redirect):
//   landing -> action 'folder' + landings[] (+ optional bound offer in offers[0])
//   offer   -> action 'redirect' + offers[]
//   direct  -> action 'redirect' + redirect.urls[]
type StepMode = 'landing' | 'offer' | 'direct';

function stepMode(s: Step): StepMode {
  if (s.action === 'folder') return 'landing';
  return s.offers.length > 0 ? 'offer' : 'direct';
}

function nameOf(records: EntityRecord[] | undefined, id: number): string {
  return records?.find((r) => r.id === id)?.name ?? `#${id}`;
}

export function StepEditor({
  step,
  index,
  isLast,
  weighted,
  onChange,
  onRemove,
  onMoveUp,
  onMoveDown,
}: {
  step: Step;
  index: number;
  isLast: boolean;
  weighted: boolean;
  onChange: (s: Step) => void;
  onRemove: () => void;
  onMoveUp?: () => void;
  onMoveDown?: () => void;
}) {
  const [landingPicker, setLandingPicker] = useState(false);
  const [offerPicker, setOfferPicker] = useState(false);
  const [boundOfferPicker, setBoundOfferPicker] = useState(false);
  const [mode, setMode] = useState<StepMode>(stepMode(step));

  const landingsQuery = useQuery({ queryKey: ['entity-list', 'landings'], queryFn: () => entityApi.list('landings') });
  const offersQuery = useQuery({ queryKey: ['entity-list', 'offers'], queryFn: () => entityApi.list('offers') });
  const landingRecords = landingsQuery.data?.items;
  const offerRecords = offersQuery.data?.items;

  const numLegacy = step.folders.length;

  const setWeight = (i: number, w: number) => {
    const weights = step.weights.slice();
    weights[i] = w;
    onChange({ ...step, weights });
  };

  const changeMode = (next: StepMode) => {
    setMode(next);
    if (next === 'landing') {
      // Keep landings + bound offer; drop direct URLs.
      onChange({ ...step, action: 'folder', redirect: { ...step.redirect, urls: [] } });
    } else if (next === 'offer') {
      onChange({
        ...step,
        action: 'redirect',
        folders: [],
        landings: [],
        folderloadtypes: {},
        redirect: { ...step.redirect, urls: [] },
      });
    } else {
      onChange({
        ...step,
        action: 'redirect',
        folders: [],
        landings: [],
        offers: [],
        folderloadtypes: {},
      });
    }
  };

  const modeOptions: { value: StepMode; label: string }[] = [
    { value: 'landing', label: 'Landing' },
    { value: 'offer', label: 'Offer' },
    { value: 'direct', label: 'Direct URL' },
  ];

  // --- landing-mode mutations (items = legacy folders, then landings) -------
  const addLanding = (rec: EntityRecord) => {
    if (step.landings.includes(rec.id)) return;
    const i = numLegacy + step.landings.length;
    const weights = step.weights.slice();
    weights[i] = weights[i] ?? 0;
    onChange({ ...step, landings: [...step.landings, rec.id], weights });
  };
  const removeLanding = (idx: number) => {
    const i = numLegacy + idx;
    onChange({
      ...step,
      landings: step.landings.filter((_, k) => k !== idx),
      weights: step.weights.filter((_, k) => k !== i),
    });
  };
  const removeFolder = (folderIdx: number, name: string) => {
    const rest = { ...step.folderloadtypes };
    delete rest[name];
    onChange({
      ...step,
      folders: step.folders.filter((_, k) => k !== folderIdx),
      weights: step.weights.filter((_, k) => k !== folderIdx),
      folderloadtypes: rest,
    });
  };
  const boundOfferId = step.offers[0];

  // --- offer-mode mutations (items = offers) --------------------------------
  const addOffer = (rec: EntityRecord) => {
    if (step.offers.includes(rec.id)) return;
    const weights = step.weights.slice();
    weights[step.offers.length] = weights[step.offers.length] ?? 0;
    onChange({ ...step, offers: [...step.offers, rec.id], weights });
  };
  const removeOffer = (idx: number) => {
    onChange({
      ...step,
      offers: step.offers.filter((_, k) => k !== idx),
      weights: step.weights.filter((_, k) => k !== idx),
    });
  };

  const WeightInput = ({ i }: { i: number }) =>
    weighted ? (
      <Input
        type="number"
        className="w-20 shrink-0"
        placeholder="%"
        value={step.weights[i] ?? ''}
        onChange={(e) => setWeight(i, parseInt(e.target.value, 10) || 0)}
      />
    ) : null;

  return (
    <div className="rounded-lg border border-border bg-surface-2/30 p-3 space-y-3">
      <div className="flex items-center gap-2">
        <span className="text-xs font-semibold text-muted">Step {index + 1}</span>
        <div className="ml-auto flex items-center gap-1">
          {onMoveUp && (
            <Button variant="ghost" size="icon" className="h-7 w-7" title="Move up" onClick={onMoveUp} disabled={step.action === 'redirect'}>
              ↑
            </Button>
          )}
          {onMoveDown && (
            <Button variant="ghost" size="icon" className="h-7 w-7" title="Move down" onClick={onMoveDown} disabled={step.action === 'redirect'}>
              ↓
            </Button>
          )}
          <Button variant="ghost" size="icon" className="h-7 w-7" title="Remove step" onClick={onRemove}>
            <Trash2 size={14} className="text-danger" />
          </Button>
        </div>
      </div>

      <ChoiceRow<StepMode>
        label="Target"
        help={!isLast ? 'Only the last step can send to an offer or a direct URL.' : undefined}
        value={mode}
        options={isLast ? modeOptions : modeOptions.filter((o) => o.value === 'landing')}
        onChange={changeMode}
      />

      {mode === 'landing' && (
        <>
          <Group
            title="Landing page(s)"
            desc="Prelander shown to the visitor. Add two or more to A/B split."
            actions={
              <Button variant="secondary" size="sm" onClick={() => setLandingPicker(true)}>
                <FileText size={14} /> Add landing
              </Button>
            }
          >
            {step.folders.length === 0 && step.landings.length === 0 ? (
              <p className="text-2xs text-faint italic">No landing selected. Add one from the catalog.</p>
            ) : (
              <div className="space-y-2">
                {step.folders.map((f, i) => (
                  <div key={`folder-${f}`} className="flex items-center gap-2">
                    <div className="flex-1">
                      <FolderRow
                        name={`${f} (legacy folder)`}
                        mode={step.folderloadtypes[f] ?? 'base'}
                        modes={['base', 'direct']}
                        onModeChange={(m) =>
                          onChange({
                            ...step,
                            folderloadtypes: { ...step.folderloadtypes, [f]: m === 'direct' ? 'direct' : 'base' },
                          })
                        }
                        onRemove={() => removeFolder(i, f)}
                      />
                    </div>
                    <WeightInput i={i} />
                  </div>
                ))}
                {step.landings.map((id, idx) => (
                  <div key={`landing-${id}`} className="flex items-center gap-2">
                    <div className="flex-1">
                      <FolderRow name={nameOf(landingRecords, id)} onRemove={() => removeLanding(idx)} />
                    </div>
                    <WeightInput i={numLegacy + idx} />
                  </div>
                ))}
              </div>
            )}
          </Group>

          <Group
            title="Offer"
            desc="The landing's {offer} / {next} link sends the visitor here. Optional."
            actions={
              <Button variant="secondary" size="sm" onClick={() => setBoundOfferPicker(true)}>
                <Target size={14} /> {boundOfferId ? 'Change offer' : 'Pick offer'}
              </Button>
            }
          >
            {boundOfferId ? (
              <div className="flex items-center gap-2 rounded-md border border-border bg-surface-2/40 px-2.5 py-1.5">
                <Link2 size={14} className="text-muted shrink-0" />
                <span className="flex-1 truncate text-sm font-medium">{nameOf(offerRecords, boundOfferId)}</span>
                <Button variant="ghost" size="icon" className="h-8 w-8" title="Remove offer" onClick={() => onChange({ ...step, offers: [] })}>
                  <Trash2 size={14} className="text-danger" />
                </Button>
              </div>
            ) : (
              <p className="text-2xs text-faint italic">
                No offer linked. The landing must contain a {'{offer}'} link to forward the visitor.
              </p>
            )}
          </Group>
        </>
      )}

      {mode === 'offer' && (
        <Group
          title="Offer(s)"
          desc="Visitor is redirected to the offer URL. Add two or more to A/B split."
          actions={
            <Button variant="secondary" size="sm" onClick={() => setOfferPicker(true)}>
              <Target size={14} /> Add offer
            </Button>
          }
        >
          {step.offers.length === 0 ? (
            <p className="text-2xs text-faint italic">No offer selected. Add one from the catalog.</p>
          ) : (
            <div className="space-y-2">
              {step.offers.map((id, idx) => (
                <div key={id} className="flex items-center gap-2">
                  <div className="flex-1">
                    <FolderRow name={nameOf(offerRecords, id)} onRemove={() => removeOffer(idx)} />
                  </div>
                  <WeightInput i={idx} />
                </div>
              ))}
            </div>
          )}
          <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-center pt-1">
            <span className="text-xs font-medium text-muted">Redirect type</span>
            <Select
              className="w-56"
              value={redirectTypeValue(step.redirect.type)}
              onChange={(e) => onChange({ ...step, redirect: { ...step.redirect, type: parseRedirectType(e.target.value) } })}
            >
              {STEP_REDIRECT_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
          </div>
        </Group>
      )}

      {mode === 'direct' && (
        <Group title="Direct URL(s)" desc="Raw redirect target(s) typed inline (no catalog entity).">
          <div className="space-y-2">
            {step.redirect.urls.map((r, i) => (
              <div key={i} className="flex items-center gap-2">
                <Input
                  className="flex-1"
                  placeholder="https://..."
                  value={r.url}
                  onChange={(e) => {
                    const urls = step.redirect.urls.slice();
                    urls[i] = { url: e.target.value, label: hostOf(e.target.value) };
                    onChange({ ...step, redirect: { ...step.redirect, urls } });
                  }}
                />
                <WeightInput i={i} />
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-9 w-9 shrink-0"
                  title="Remove"
                  onClick={() =>
                    onChange({
                      ...step,
                      redirect: { ...step.redirect, urls: step.redirect.urls.filter((_, idx) => idx !== i) },
                      weights: step.weights.filter((_, idx) => idx !== i),
                    })
                  }
                >
                  <Trash2 size={15} className="text-danger" />
                </Button>
              </div>
            ))}
            <Button
              variant="secondary"
              size="sm"
              onClick={() => onChange({ ...step, redirect: { ...step.redirect, urls: [...step.redirect.urls, { url: '', label: '' }] } })}
            >
              <Plus size={14} /> Add URL
            </Button>
          </div>
          <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-center pt-1">
            <span className="text-xs font-medium text-muted">Redirect type</span>
            <Select
              className="w-56"
              value={redirectTypeValue(step.redirect.type)}
              onChange={(e) => onChange({ ...step, redirect: { ...step.redirect, type: parseRedirectType(e.target.value) } })}
            >
              {STEP_REDIRECT_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
          </div>
        </Group>
      )}

      <EntityPickerModal
        open={landingPicker}
        onClose={() => setLandingPicker(false)}
        entity="landings"
        localLandingsOnly
        exclude={step.landings}
        onPick={addLanding}
      />
      <EntityPickerModal
        open={offerPicker}
        onClose={() => setOfferPicker(false)}
        entity="offers"
        exclude={step.offers}
        onPick={addOffer}
      />
      <EntityPickerModal
        open={boundOfferPicker}
        onClose={() => setBoundOfferPicker(false)}
        entity="offers"
        onPick={(rec) => onChange({ ...step, offers: [rec.id] })}
      />
    </div>
  );
}

import { useState } from 'react';
import { FolderInput, UploadCloud, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input, Select } from '@/components/ui/Field';
import { FileManager } from '@/components/files/FileManager';
import { ZipUploadModal } from '@/components/files/ZipUploadModal';
import { hostOf, type Step, type StepAction, type StepRedirectType } from '@/lib/campaign';
import { ChoiceRow, FolderRow, FolderPickerModal, Group } from './parts';

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
  const [picker, setPicker] = useState(false);
  const [zip, setZip] = useState(false);
  const [editFolder, setEditFolder] = useState<string | null>(null);

  const setWeight = (i: number, w: number) => {
    const weights = step.weights.slice();
    weights[i] = w;
    onChange({ ...step, weights });
  };

  const actionOptions: { value: StepAction; label: string }[] = [
    { value: 'folder', label: 'Local page(s) from folder' },
    { value: 'redirect', label: 'Redirect(s)' },
  ];

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

      <ChoiceRow<StepAction>
        label="Action"
        help={!isLast ? 'Only the last step can use redirects.' : undefined}
        value={step.action}
        options={isLast ? actionOptions : actionOptions.filter((o) => o.value === 'folder')}
        onChange={(action) => onChange({ ...step, action })}
      />

      {step.action === 'folder' ? (
        <Group
          title="Folders"
          actions={
            <>
              <Button variant="secondary" size="sm" onClick={() => setPicker(true)}>
                <FolderInput size={14} /> Add existing
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setZip(true)}>
                <UploadCloud size={14} /> Upload ZIP
              </Button>
            </>
          }
        >
          {step.folders.length === 0 ? (
            <p className="text-2xs text-faint italic">No folders. Add one or more landing folders.</p>
          ) : (
            <div className="space-y-2">
              {step.folders.map((f, i) => (
                <div key={f} className="flex items-center gap-2">
                  <div className="flex-1">
                    <FolderRow
                      name={f}
                      mode={step.folderloadtypes[f] ?? 'base'}
                      modes={['base', 'direct']}
                      onModeChange={(m) =>
                        onChange({ ...step, folderloadtypes: { ...step.folderloadtypes, [f]: m === 'direct' ? 'direct' : 'base' } })
                      }
                      onEdit={() => setEditFolder(f)}
                      onRemove={() => {
                        const rest = { ...step.folderloadtypes };
                        delete rest[f];
                        onChange({
                          ...step,
                          folders: step.folders.filter((x) => x !== f),
                          weights: step.weights.filter((_, idx) => idx !== i),
                          folderloadtypes: rest,
                        });
                      }}
                    />
                  </div>
                  {weighted && (
                    <Input
                      type="number"
                      className="w-20 shrink-0"
                      placeholder="%"
                      value={step.weights[i] ?? ''}
                      onChange={(e) => setWeight(i, parseInt(e.target.value, 10) || 0)}
                    />
                  )}
                </div>
              ))}
            </div>
          )}
        </Group>
      ) : (
        <Group title="Redirects">
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
                {weighted && (
                  <Input
                    type="number"
                    className="w-20 shrink-0"
                    placeholder="%"
                    value={step.weights[i] ?? ''}
                    onChange={(e) => setWeight(i, parseInt(e.target.value, 10) || 0)}
                  />
                )}
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
              + Add redirect
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

      <FolderPickerModal
        open={picker}
        onClose={() => setPicker(false)}
        type="landing"
        exclude={step.folders}
        onPick={(f) => onChange({ ...step, folders: [...step.folders, f], folderloadtypes: { ...step.folderloadtypes, [f]: 'base' } })}
      />
      <ZipUploadModal
        open={zip}
        onClose={() => setZip(false)}
        type="landing"
        onUploaded={(f) =>
          onChange({ ...step, folders: [...new Set([...step.folders, f])], folderloadtypes: { ...step.folderloadtypes, [f]: 'base' } })
        }
      />
      {editFolder && (
        <FileManager folder={editFolder} type="landing" title={`Landing: ${editFolder}`} onClose={() => setEditFolder(null)} />
      )}
    </div>
  );
}

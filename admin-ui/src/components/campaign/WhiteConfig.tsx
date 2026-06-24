import { useState } from 'react';
import { FolderInput, UploadCloud } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Field';
import { FileManager } from '@/components/files/FileManager';
import { ZipUploadModal } from '@/components/files/ZipUploadModal';
import type { LoadMode, WhiteAction } from '@/lib/campaign';
import { ChoiceRow, FolderRow, FolderPickerModal, LoadModeToggle, StringListEditor, Group } from './parts';

export interface WhiteConfigValue {
  action: WhiteAction;
  folders: string[];
  redirect: { urls: string[]; type: number };
  curls: string[];
  errorcodes: string[];
  loadmode: Record<string, LoadMode>;
}

const ACTIONS: { value: WhiteAction; label: string }[] = [
  { value: 'folder', label: 'Local folder' },
  { value: 'redirect', label: 'Redirect' },
  { value: 'curl', label: 'CURL' },
  { value: 'error', label: 'HTTP code' },
];

export function WhiteConfig({
  value,
  onChange,
}: {
  value: WhiteConfigValue;
  onChange: (v: WhiteConfigValue) => void;
}) {
  const [picker, setPicker] = useState(false);
  const [zip, setZip] = useState(false);
  const [editFolder, setEditFolder] = useState<string | null>(null);

  const setMode = (key: string, m: LoadMode) =>
    onChange({ ...value, loadmode: { ...value.loadmode, [key]: m } });

  return (
    <div className="space-y-3">
      <Group title="Method">
        <ChoiceRow<WhiteAction>
          label="Safe page delivery"
          value={value.action}
          options={ACTIONS}
          onChange={(action) => onChange({ ...value, action })}
        />
      </Group>

      {value.action === 'folder' && (
        <Group
          title="Safe page folders"
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
          {value.folders.length === 0 ? (
            <p className="text-2xs text-faint italic">No safe page folder selected.</p>
          ) : (
            <div className="space-y-2">
              {value.folders.map((f) => (
                <FolderRow
                  key={f}
                  name={f}
                  mode={value.loadmode[f] ?? 'base'}
                  modes={['base', 'rewrite', 'direct']}
                  onModeChange={(m) => setMode(f, m)}
                  onEdit={() => setEditFolder(f)}
                  onRemove={() => onChange({ ...value, folders: value.folders.filter((x) => x !== f) })}
                />
              ))}
            </div>
          )}
        </Group>
      )}

      {value.action === 'redirect' && (
        <Group title="Redirect">
          <StringListEditor
            items={value.redirect.urls}
            onChange={(urls) => onChange({ ...value, redirect: { ...value.redirect, urls } })}
            placeholder="https://ya.ru"
            addLabel="Add redirect"
          />
          <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-center pt-1">
            <span className="text-xs font-medium text-muted">Redirect type</span>
            <Select
              className="w-32"
              value={String(value.redirect.type)}
              onChange={(e) => onChange({ ...value, redirect: { ...value.redirect, type: parseInt(e.target.value, 10) } })}
            >
              {[301, 302, 303, 307].map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </Select>
          </div>
        </Group>
      )}

      {value.action === 'curl' && (
        <Group title="CURL" desc="Load a remote website server-side.">
          <StringListEditor
            items={value.curls}
            onChange={(curls) => onChange({ ...value, curls })}
            placeholder="https://example.com"
            addLabel="Add CURL URL"
            renderExtra={(i) => {
              const url = value.curls[i];
              if (!url) return null;
              return (
                <LoadModeToggle
                  mode={value.loadmode[url] ?? 'rewrite'}
                  modes={['rewrite', 'direct']}
                  onChange={(m) => setMode(url, m)}
                />
              );
            }}
          />
        </Group>
      )}

      {value.action === 'error' && (
        <Group title="HTTP code" desc="Return a raw HTTP status (e.g. 404 Not Found, 200 OK).">
          <StringListEditor
            items={value.errorcodes}
            onChange={(errorcodes) => onChange({ ...value, errorcodes })}
            placeholder="404"
            addLabel="Add HTTP code"
          />
        </Group>
      )}

      <FolderPickerModal
        open={picker}
        onClose={() => setPicker(false)}
        type="white"
        exclude={value.folders}
        onPick={(f) => onChange({ ...value, folders: [...value.folders, f], loadmode: { ...value.loadmode, [f]: 'base' } })}
      />
      <ZipUploadModal
        open={zip}
        onClose={() => setZip(false)}
        type="white"
        onUploaded={(f) =>
          onChange({ ...value, folders: [...new Set([...value.folders, f])], loadmode: { ...value.loadmode, [f]: 'base' } })
        }
      />
      {editFolder && (
        <FileManager folder={editFolder} type="white" title={`Safe page: ${editFolder}`} onClose={() => setEditFolder(null)} />
      )}
    </div>
  );
}

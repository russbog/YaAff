import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Field';
import {
  flowHasMultipleSteps,
  newStep,
  type Distribution,
  type Flow,
  type FlowType,
  type Step,
} from '@/lib/campaign';
import { FilterBuilder } from './FilterBuilder';
import { StepEditor } from './StepEditor';
import { ChoiceRow, Group } from './parts';

export function FlowEditor({ flow, onChange }: { flow: Flow; onChange: (f: Flow) => void }) {
  const weighted = flow.distribution === 'weighted';
  const hasRedirect = flow.steps.some((s) => s.action === 'redirect');
  const multiStep = flowHasMultipleSteps(flow);

  const updateStep = (i: number, s: Step) => {
    const steps = flow.steps.slice();
    steps[i] = s;
    onChange({ ...flow, steps });
  };
  const removeStep = (i: number) => onChange({ ...flow, steps: flow.steps.filter((_, idx) => idx !== i) });
  const moveStep = (i: number, dir: -1 | 1) => {
    const j = i + dir;
    if (j < 0 || j >= flow.steps.length) return;
    const steps = flow.steps.slice();
    [steps[i], steps[j]] = [steps[j], steps[i]];
    onChange({ ...flow, steps });
  };

  return (
    <div className="space-y-3">
      <Group title="Flow filters" desc="Traffic matching these filters enters this flow. Empty = catch-all.">
        <FilterBuilder value={flow.filters} onChange={(filters) => onChange({ ...flow, filters })} />
      </Group>

      <Group title="Routing">
        <ChoiceRow<FlowType>
          label="Flow type"
          value={flow.type}
          options={[
            { value: 'regular', label: 'Regular (weighted split)' },
            { value: 'forced', label: 'Forced (checked first)' },
            { value: 'default', label: 'Default (fallback)' },
          ]}
          onChange={(type) => onChange({ ...flow, type })}
        />
        <div className="grid gap-2 sm:grid-cols-[200px_1fr] sm:items-center">
          <span className="text-xs font-medium text-muted">Flow weight</span>
          <Input
            type="number"
            min={0}
            className="w-28"
            value={flow.weight}
            onChange={(e) => onChange({ ...flow, weight: parseInt(e.target.value, 10) || 0 })}
          />
        </div>
        <ChoiceRow<Distribution>
          label="Distribution"
          value={flow.distribution}
          options={[
            { value: 'equal', label: 'Equal' },
            { value: 'weighted', label: 'Weighted' },
            { value: 'thompson', label: 'Thompson Sampling' },
          ]}
          onChange={(distribution) => onChange({ ...flow, distribution })}
        />

        {flow.distribution === 'thompson' && (
          <div className="rounded-md border border-border bg-surface-2/40 p-3 space-y-3">
            <ChoiceRow<'Lead' | 'Purchase'>
              label="Optimize for"
              value={flow.optimize_for}
              options={[
                { value: 'Lead', label: 'Lead' },
                { value: 'Purchase', label: 'Purchase' },
              ]}
              onChange={(optimize_for) => onChange({ ...flow, optimize_for })}
            />
            {multiStep && (
              <ChoiceRow<'funnels' | 'separate'>
                label="Optimize mode"
                value={flow.optimize_mode}
                options={[
                  { value: 'funnels', label: 'Funnels (step combos)' },
                  { value: 'separate', label: 'Separate (per step)' },
                ]}
                onChange={(optimize_mode) => onChange({ ...flow, optimize_mode })}
              />
            )}
            <p className="text-2xs text-faint">
              Win probabilities are computed from live impressions and shown in analytics once enough data is collected.
            </p>
          </div>
        )}
      </Group>

      <Group
        title="Steps"
        desc="Visitors progress through steps in order. Only the last step may redirect."
        actions={
          <Button
            variant="secondary"
            size="sm"
            disabled={hasRedirect}
            title={hasRedirect ? 'A redirect step must be the last step' : 'Add step'}
            onClick={() => onChange({ ...flow, steps: [...flow.steps, newStep('folder')] })}
          >
            <Plus size={14} /> Add step
          </Button>
        }
      >
        <div className="space-y-3">
          {flow.steps.map((s, i) => (
            <StepEditor
              key={i}
              step={s}
              index={i}
              isLast={i === flow.steps.length - 1}
              weighted={weighted}
              onChange={(ns) => updateStep(i, ns)}
              onRemove={() => removeStep(i)}
              onMoveUp={i > 0 ? () => moveStep(i, -1) : undefined}
              onMoveDown={i < flow.steps.length - 1 ? () => moveStep(i, 1) : undefined}
            />
          ))}
          {flow.steps.length === 0 && (
            <p className="text-2xs text-faint italic">No steps. Add at least one.</p>
          )}
        </div>
      </Group>
    </div>
  );
}

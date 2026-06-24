import { Plus, FolderPlus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input, Select } from '@/components/ui/Field';
import { Segmented } from '@/components/ui/Segmented';
import { cn } from '@/lib/cn';
import {
  type Condition,
  type FilterGroup,
  type FilterNode,
  type FilterRule,
  isGroup,
} from '@/lib/campaign';
import {
  FILTER_FIELDS,
  FILTER_FIELD_MAP,
  NO_VALUE_OPERATORS,
  OPERATOR_LABELS,
  PARAM_OPERATORS,
  type FilterField,
} from './filterFields';

function newRule(field: FilterField): FilterRule {
  return {
    id: field.id,
    field: field.id,
    type: field.type,
    input: field.input,
    operator: field.operators[0],
    value: field.input === 'radio' ? Object.keys(field.values ?? { '0': '' })[0] : '',
  };
}

function fieldFor(rule: FilterRule): FilterField {
  return FILTER_FIELD_MAP[rule.id] ?? FILTER_FIELDS[0];
}

function RuleRow({
  rule,
  onChange,
  onRemove,
}: {
  rule: FilterRule;
  onChange: (r: FilterRule) => void;
  onRemove: () => void;
}) {
  const field = fieldFor(rule);
  const isParam = PARAM_OPERATORS.has(rule.operator);
  const noValue = NO_VALUE_OPERATORS.has(rule.operator);
  const paramVal = Array.isArray(rule.value) ? (rule.value as unknown[]) : ['', ''];

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-2/40 p-2">
      <Select
        className="h-8 w-40 text-xs"
        value={rule.id}
        onChange={(e) => {
          const f = FILTER_FIELD_MAP[e.target.value];
          onChange(newRule(f));
        }}
      >
        {FILTER_FIELDS.map((f) => (
          <option key={f.id} value={f.id}>
            {f.label}
          </option>
        ))}
      </Select>

      <Select
        className="h-8 w-36 text-xs"
        value={rule.operator}
        onChange={(e) => {
          const op = e.target.value;
          const wasParam = PARAM_OPERATORS.has(rule.operator);
          const nowParam = PARAM_OPERATORS.has(op);
          let value = rule.value;
          if (nowParam && !wasParam) value = ['', ''];
          else if (!nowParam && wasParam) value = '';
          onChange({ ...rule, operator: op, value });
        }}
      >
        {field.operators.map((op) => (
          <option key={op} value={op}>
            {OPERATOR_LABELS[op] ?? op}
          </option>
        ))}
      </Select>

      {field.input === 'radio' && !isParam ? (
        <Select
          className="h-8 w-40 text-xs"
          value={String(rule.value)}
          onChange={(e) => onChange({ ...rule, value: e.target.value })}
        >
          {Object.entries(field.values ?? {}).map(([k, label]) => (
            <option key={k} value={k}>
              {label}
            </option>
          ))}
        </Select>
      ) : isParam ? (
        <>
          <Input
            className="h-8 w-32 text-xs"
            placeholder="param name"
            value={String(paramVal[0] ?? '')}
            onChange={(e) => onChange({ ...rule, value: [e.target.value, paramVal[1] ?? ''] })}
          />
          {!noValue && (
            <Input
              className="h-8 w-40 text-xs"
              placeholder="value(s), comma-separated"
              value={String(paramVal[1] ?? '')}
              onChange={(e) => onChange({ ...rule, value: [paramVal[0] ?? '', e.target.value] })}
            />
          )}
        </>
      ) : (
        <Input
          className="h-8 flex-1 min-w-[140px] text-xs"
          type={field.input === 'number' ? 'number' : 'text'}
          placeholder={field.placeholder ?? 'value(s), comma-separated'}
          value={String(rule.value ?? '')}
          onChange={(e) => onChange({ ...rule, value: e.target.value })}
        />
      )}

      <Button variant="ghost" size="icon" className="h-8 w-8 ml-auto" onClick={onRemove} title="Remove">
        <Trash2 size={14} className="text-danger" />
      </Button>
    </div>
  );
}

function GroupBlock({
  group,
  onChange,
  onRemove,
  depth,
}: {
  group: FilterGroup;
  onChange: (g: FilterGroup) => void;
  onRemove?: () => void;
  depth: number;
}) {
  const update = (idx: number, node: FilterNode) => {
    const rules = group.rules.slice();
    rules[idx] = node;
    onChange({ ...group, rules });
  };
  const remove = (idx: number) => {
    onChange({ ...group, rules: group.rules.filter((_, i) => i !== idx) });
  };
  const addRule = () => {
    onChange({ ...group, rules: [...group.rules, newRule(FILTER_FIELDS[0])] });
  };
  const addGroup = () => {
    onChange({ ...group, rules: [...group.rules, { condition: 'AND', rules: [] }] });
  };

  return (
    <div className={cn('rounded-lg border border-border p-3 space-y-2', depth > 0 && 'bg-surface-2/30')}>
      <div className="flex items-center gap-2">
        <Segmented<Condition>
          size="sm"
          value={group.condition}
          onChange={(c) => onChange({ ...group, condition: c })}
          options={[
            { value: 'AND', label: 'AND' },
            { value: 'OR', label: 'OR' },
          ]}
        />
        <span className="text-2xs text-faint">
          {group.condition === 'AND' ? 'all conditions must match' : 'any condition matches'}
        </span>
        <div className="ml-auto flex items-center gap-1.5">
          <Button variant="ghost" size="sm" onClick={addRule}>
            <Plus size={13} /> Rule
          </Button>
          <Button variant="ghost" size="sm" onClick={addGroup}>
            <FolderPlus size={13} /> Group
          </Button>
          {onRemove && (
            <Button variant="ghost" size="icon" className="h-7 w-7" onClick={onRemove} title="Remove group">
              <Trash2 size={14} className="text-danger" />
            </Button>
          )}
        </div>
      </div>

      {group.rules.length === 0 ? (
        <p className="text-2xs text-faint italic px-1 py-2">
          No conditions — this matches all traffic (catch-all).
        </p>
      ) : (
        <div className="space-y-2">
          {group.rules.map((node, idx) =>
            isGroup(node) ? (
              <GroupBlock
                key={idx}
                group={node}
                depth={depth + 1}
                onChange={(g) => update(idx, g)}
                onRemove={() => remove(idx)}
              />
            ) : (
              <RuleRow
                key={idx}
                rule={node}
                onChange={(r) => update(idx, r)}
                onRemove={() => remove(idx)}
              />
            ),
          )}
        </div>
      )}
    </div>
  );
}

// Native replacement for the jQuery QueryBuilder. Emits the same
// { condition, rules:[{ id, field, type, input, operator, value }] } JSON that
// core.php's match_filters() consumes.
export function FilterBuilder({
  value,
  onChange,
}: {
  value: FilterGroup;
  onChange: (g: FilterGroup) => void;
}) {
  return <GroupBlock group={value} onChange={onChange} depth={0} />;
}

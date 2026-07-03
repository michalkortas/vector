import '../css/app.css';
import { createInertiaApp, router, usePage } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Activity, Lock, Play, RefreshCw, Unlock } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';

type Period = { id: number; name: string; starts_on: string; ends_on: string; monthly_norm_minutes: number; quarterly_norm_minutes: number };
type PlanningRun = { id: number; status: string; score_total: number | null; hard_violations_count: number; soft_violations_count: number; unassigned_slots_count: number; metadata?: { phase?: string; evaluated_candidates?: number; estimated_candidates?: number; completed_generations?: number; configured_generations?: number; configured_population_size?: number; progress_percent?: number; best_score?: number; stop_reason?: string; time_limit_seconds?: number } } | null;
type Assignment = { id: number; demand_slot_id: number; resource_id: number | null; slot_position: number; segment_position: number; is_locked: boolean | number; source: string; display_layer: 'demand' | 'top_up' | 'resource_only'; metadata: { segment_kind?: string } | null; employee_number: number | null; resource_name: string | null; starts_at: string; ends_at: string; duration_minutes: number; slot_starts_at: string; unit_name: string; shift_code: string; shift_name: string };
type Resource = {
  id: number;
  employee_number: number;
  name: string;
  is_active: boolean;
  employment_type: string;
  workload_policy: string;
  planned_duties_note: string;
  target_minutes_per_month: number;
  target_minutes_per_quarter: number;
  max_minutes_per_month: number;
  max_minutes_per_quarter: number;
  planned_work_minutes: number;
  planned_absence_minutes: number;
  planned_total_minutes: number;
  remaining_work_minutes: number | null;
};
type Violation = { id: number; code: string; severity: string; message: string; demand_slot_id: number | null; resource_id: number | null; employee_number: number | null; resource_name: string | null; metadata?: { missing_minutes?: number; planned_work_minutes?: number; paid_absence_minutes?: number; target_minutes?: number } | null };
type ScoreComponent = { id: number; code: string; label: string; score: number; hard: boolean };
type PlanningRule = { code: string; name: string; type: string; is_active: boolean; can_toggle: boolean; weight: number };
type Absence = { employee_number: number; resource_name: string; type_name: string; starts_at: string; ends_at: string };
type Holiday = { holiday_date: string; name: string; scope: string; blocks_planning: boolean };
type ScheduleRow = { shift_code: string; shift_name: string; unit_name: string };

function minutes(value: number | null | undefined) {
  if (!value) return '-';
  const h = Math.floor(value / 60);
  const m = value % 60;
  return `${h}h ${m.toString().padStart(2, '0')}m`;
}

function dayOfMonth(date: string) {
  return Number(date.slice(8, 10));
}

function dateOnly(value: string) {
  return value.slice(0, 10);
}

function timeOnly(value: string) {
  return value.slice(11, 16);
}

function timeRange(value: Assignment) {
  return `${timeOnly(value.starts_at)}-${timeOnly(value.ends_at)}`;
}

function dateForDay(period: Period | null, day: number) {
  const prefix = period?.starts_on?.slice(0, 8) ?? '2026-07-';
  return `${prefix}${day.toString().padStart(2, '0')}`;
}

function stopReasonLabel(run: PlanningRun) {
  if (!run || run.status !== 'completed') return '-';
  if (run.metadata?.stop_reason === 'time_limit') return `Limit czasu${run.metadata.time_limit_seconds ? ` ${run.metadata.time_limit_seconds}s` : ''}`;
  if (run.metadata?.stop_reason === 'stagnation') return 'Brak poprawy';
  if (run.metadata?.stop_reason === 'generations_completed') return 'Pełny limit generacji';
  return '-';
}

function selectedDayInfo(dayDate: string, employeeNumber: number | null, assignments: Assignment[], absences: Absence[]) {
  if (employeeNumber === null) {
    return { type: 'off', label: 'dW', title: 'Dzień wolny' };
  }

  const absence = absences.find((item) => item.employee_number === employeeNumber && dayDate >= dateOnly(item.starts_at) && dayDate < dateOnly(item.ends_at));
  if (absence) {
    return {
      type: 'absence',
      label: absence.type_name.toLowerCase().includes('urlop') ? 'Urlop' : absence.type_name,
      title: absence.type_name,
    };
  }

  const work = assignments.filter((assignment) => assignment.employee_number === employeeNumber && dateOnly(assignment.starts_at) === dayDate);
  if (work.length > 0) {
    return {
      type: 'work',
      label: Array.from(new Set(work.map((assignment) => assignment.unit_name))).join(' / '),
      title: work.map((assignment) => `${assignment.shift_name}: ${assignment.unit_name}`).join(', '),
    };
  }

  return { type: 'off', label: 'dW', title: 'Dzień wolny' };
}

function violationDetails(violation: Violation) {
  if (!['nominal_underfilled', 'nominal_carryover'].includes(violation.code) || !violation.metadata?.missing_minutes) {
    return null;
  }

  return `brakuje ${minutes(violation.metadata.missing_minutes)} · praca ${minutes(violation.metadata.planned_work_minutes)} · urlopy ${minutes(violation.metadata.paid_absence_minutes)} · nominał ${minutes(violation.metadata.target_minutes)}`;
}

function violationStyle(violation: Violation) {
  return violation.severity === 'hard'
    ? 'border-red-200 bg-red-50 text-red-950'
    : 'border-amber-200 bg-amber-50 text-amber-950';
}

function AppShell({ children }: { children: React.ReactNode }) {
  const page = usePage<{ appName: string; flash: { message?: string } }>();
  return (
    <main className="min-h-screen">
      <header className="border-b border-zinc-200 bg-white">
        <div className="flex w-full items-center justify-between px-5 py-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">Vector</p>
            <h1 className="text-xl font-semibold text-zinc-950">Planowanie zasobów</h1>
          </div>
          <a className="rounded-md border border-zinc-300 px-3 py-2 text-sm" href="/">Odśwież</a>
        </div>
      </header>
      {page.props.flash?.message && <div className="border-b border-emerald-200 bg-emerald-50 px-5 py-2 text-sm text-emerald-900">{page.props.flash.message}</div>}
      {children}
    </main>
  );
}

function Schedule(props: { period: Period | null; latestRun: PlanningRun; assignments: Assignment[]; scheduleRows: ScheduleRow[]; resources: Resource[]; units: { name: string }[]; shifts: { code: string; name: string }[]; violations: Violation[]; scoreComponents: ScoreComponent[]; planningRules: PlanningRule[]; holidays: Holiday[]; absences: Absence[] }) {
  const [highlightedEmployeeNumber, setHighlightedEmployeeNumber] = useState<number | null>(5);
  const [activeAssignmentId, setActiveAssignmentId] = useState<number | null>(null);
  const [isSubmittingGeneration, setIsSubmittingGeneration] = useState(false);
  const [isWaitingForRunRefresh, setIsWaitingForRunRefresh] = useState(false);
  const [isPolling, setIsPolling] = useState(false);
  const wasGenerating = useRef(false);
  const isGenerating = props.latestRun?.status === 'queued' || props.latestRun?.status === 'running';
  const shouldPoll = isGenerating || isSubmittingGeneration || isWaitingForRunRefresh;
  const progressPercent = props.latestRun?.metadata?.progress_percent ?? (props.latestRun?.status === 'completed' ? 100 : 0);
  const highlightedResource = props.resources.find((resource) => resource.employee_number === highlightedEmployeeNumber) ?? null;
  const days = Array.from({ length: 31 }, (_, index) => index + 1);
  const holidayDays = new Set(props.holidays.filter((holiday) => holiday.blocks_planning).map((holiday) => dayOfMonth(holiday.holiday_date)));
  const byCell = new Map<string, Assignment[]>();
  const topUpByCell = new Map<string, Assignment[]>();
  const topUpRows = new Set<string>();
  for (const assignment of props.assignments) {
    if (assignment.display_layer === 'resource_only') {
      continue;
    }

    const day = new Date(assignment.slot_starts_at).getDate();
    const key = `${assignment.shift_code}:${assignment.unit_name}:${day}`;
    const target = assignment.display_layer === 'top_up' ? topUpByCell : byCell;
    target.set(key, [...(target.get(key) ?? []), assignment].sort((a, b) => a.starts_at.localeCompare(b.starts_at) || a.segment_position - b.segment_position));
    if (assignment.display_layer === 'top_up') {
      topUpRows.add(`${assignment.shift_code}:${assignment.unit_name}`);
    }
  }
  const violationKeys = new Set<string>();
  for (const violation of props.violations) {
    if (violation.demand_slot_id === null) continue;
    violationKeys.add(`slot:${violation.demand_slot_id}`);
    if (violation.resource_id !== null) {
      violationKeys.add(`slot-resource:${violation.demand_slot_id}:${violation.resource_id}`);
    }
  }

  const generate = () => {
    if (!props.period) return;
    setIsSubmittingGeneration(true);
    setIsWaitingForRunRefresh(true);
    router.post(`/planning-periods/${props.period.id}/planning-runs`, { random_seed: 202607 }, {
      preserveScroll: true,
      onSuccess: () => router.reload({ only: ['latestRun'], preserveScroll: true }),
      onFinish: () => setIsSubmittingGeneration(false),
    });
  };

  useEffect(() => {
    if (!shouldPoll) {
      if (wasGenerating.current) {
        setIsPolling(false);
        wasGenerating.current = false;
      }
      return;
    }

    wasGenerating.current = true;
    const reloadProgress = () => {
      setIsPolling(true);
      router.reload({
        only: ['latestRun', 'assignments', 'violations', 'scoreComponents', 'resources'],
        preserveScroll: true,
        onFinish: () => setIsPolling(false),
      });
    };
    reloadProgress();
    const interval = window.setInterval(reloadProgress, 1000);

    return () => window.clearInterval(interval);
  }, [shouldPoll, props.latestRun?.id]);

  useEffect(() => {
    if (props.latestRun && ['queued', 'running'].includes(props.latestRun.status)) {
      setIsWaitingForRunRefresh(false);
    }
    if (props.latestRun && ['completed', 'failed'].includes(props.latestRun.status)) {
      setIsWaitingForRunRefresh(false);
      setIsPolling(false);
    }
  }, [props.latestRun?.id, props.latestRun?.status]);

  return (
    <AppShell>
      <section className="w-full px-5 py-5">
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold">{props.period?.name ?? 'Brak okresu'}</h2>
            <p className="text-sm text-zinc-600">Norma miesięczna {minutes(props.period?.monthly_norm_minutes)} · kwartalna {minutes(props.period?.quarterly_norm_minutes)}</p>
          </div>
          <div className="flex gap-2">
            <button disabled={isGenerating || isSubmittingGeneration} onClick={generate} className="inline-flex items-center gap-2 rounded-md bg-zinc-950 px-3 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:bg-zinc-500"><Play size={16} />{isGenerating || isSubmittingGeneration ? 'Generowanie...' : 'Generuj grafik'}</button>
            <button onClick={() => router.reload()} className="inline-flex items-center gap-2 rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm"><RefreshCw size={16} />Status</button>
          </div>
        </div>

        {(isGenerating || isSubmittingGeneration || isPolling || isWaitingForRunRefresh) && (
          <div className="mb-4 border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span>{isWaitingForRunRefresh ? 'Uruchamiam generowanie.' : 'Generowanie trwa.'} Status odświeża się automatycznie co 2 sekundy.</span>
              <span className="font-semibold">{progressPercent}%</span>
            </div>
            <div className="mt-2 h-2 overflow-hidden bg-sky-100">
              <div className="h-full bg-sky-700 transition-all" style={{ width: `${Math.max(3, Math.min(100, progressPercent))}%` }} />
            </div>
          </div>
        )}

        <div className="mb-4 grid gap-3 md:grid-cols-7">
          <Metric label="Status" value={props.latestRun?.status ?? 'brak'} />
          <Metric label="Score" value={props.latestRun?.score_total?.toString() ?? '-'} />
          <Metric label="Próby" value={props.latestRun?.metadata?.evaluated_candidates !== undefined ? `${props.latestRun.metadata.evaluated_candidates}${props.latestRun.metadata.estimated_candidates ? `/${props.latestRun.metadata.estimated_candidates}` : ''}` : (isGenerating ? 'w toku' : '-')} />
          <Metric label="Generacja" value={props.latestRun?.metadata?.configured_generations ? `${props.latestRun.metadata.completed_generations ?? 0}/${props.latestRun.metadata.configured_generations}` : '-'} />
          <Metric label="Zakończenie" value={stopReasonLabel(props.latestRun)} />
          <Metric label="Hard violations" value={props.latestRun?.hard_violations_count?.toString() ?? '0'} />
          <Metric label="Nieobsadzone" value={props.latestRun?.unassigned_slots_count?.toString() ?? '0'} />
        </div>

        <section className="mb-4 bg-white p-4 ring-1 ring-zinc-200">
          <div className="mb-3 flex items-center justify-between gap-3">
            <h3 className="font-semibold">Reguły planowania</h3>
            <span className="text-xs text-zinc-500">Aktywne przy następnym generowaniu</span>
          </div>
          <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
            {props.planningRules.map((rule) => <PlanningRuleRow key={rule.code} rule={rule} />)}
          </div>
        </section>

        <div className="overflow-x-auto border border-zinc-300 bg-white">
          <table className="w-full min-w-[1600px] table-fixed border-collapse text-xs">
            <colgroup>
              <col className="w-56" />
              {days.map((day) => <col key={day} />)}
            </colgroup>
            <thead>
              <tr>
                <th className="sticky left-0 z-10 border border-zinc-300 bg-zinc-50 p-2 text-left">Zmiana / jednostka</th>
                {days.map((day) => <th key={day} className={`border border-zinc-300 p-2 text-center ${holidayDays.has(day) ? 'bg-rose-100 text-rose-950' : [4,5,11,12,18,19,25,26].includes(day) ? 'bg-zinc-200' : 'bg-zinc-50'}`}>{day}</th>)}
              </tr>
            </thead>
            <tbody>
              {props.scheduleRows.map((row) => {
                const shiftCode = row.shift_code;
                const unitName = row.unit_name;
                const rowKey = `${shiftCode}:${unitName}`;
                const hasTopUpRow = topUpRows.has(rowKey);
                return (
                  <Fragment key={rowKey}>
                    {hasTopUpRow && (
                      <tr className="border-t-2 border-t-emerald-200">
                        <th className="sticky left-0 z-10 border border-emerald-200 bg-emerald-50 p-2 text-left font-medium text-emerald-950">dopełnienie {unitName}</th>
                        {days.map((day) => {
                          const cellAssignments = topUpByCell.get(`${shiftCode}:${unitName}:${day}`) ?? [];
                          const primaryAssignment = cellAssignments[0];
                          const hasViolation = primaryAssignment ? (
                            violationKeys.has(`slot:${primaryAssignment.demand_slot_id}`)
                            || cellAssignments.some((assignment) => violationKeys.has(`slot-resource:${assignment.demand_slot_id}:${assignment.resource_id ?? 'null'}`))
                          ) : false;

                          return (
                            <ScheduleCell
                              key={day}
                              assignments={cellAssignments}
                              resources={props.resources}
                              highlightedEmployeeNumber={highlightedEmployeeNumber}
                              isHoliday={holidayDays.has(day)}
                              isActive={cellAssignments.some((assignment) => activeAssignmentId === assignment.id)}
                              hasViolation={hasViolation}
                              onActivate={(assignmentId) => setActiveAssignmentId(assignmentId)}
                              emptyVariant="blank"
                            />
                          );
                        })}
                      </tr>
                    )}
                    <tr>
                      <th className="sticky left-0 z-10 border border-zinc-300 bg-white p-2 text-left font-medium">{shiftCode}<br /><span className="font-normal text-zinc-600">{unitName}</span></th>
                      {days.map((day) => {
                        const cellAssignments = byCell.get(`${shiftCode}:${unitName}:${day}`) ?? [];
                        const primaryAssignment = cellAssignments[0];
                        const hasViolation = primaryAssignment ? (
                          violationKeys.has(`slot:${primaryAssignment.demand_slot_id}`)
                          || cellAssignments.some((assignment) => violationKeys.has(`slot-resource:${assignment.demand_slot_id}:${assignment.resource_id ?? 'null'}`))
                        ) : false;

                        return (
                          <ScheduleCell
                            key={day}
                            assignments={cellAssignments}
                            resources={props.resources}
                            highlightedEmployeeNumber={highlightedEmployeeNumber}
                            isHoliday={holidayDays.has(day)}
                            isActive={cellAssignments.some((assignment) => activeAssignmentId === assignment.id)}
                            hasViolation={hasViolation}
                            onActivate={(assignmentId) => setActiveAssignmentId(assignmentId)}
                          />
                        );
                      })}
                    </tr>
                  </Fragment>
                );
              })}
              <tr className="border-t-4 border-t-zinc-900">
                <th className="sticky left-0 z-10 border border-zinc-300 bg-zinc-900 p-2 text-left font-medium text-white">
                  Wybrany zasób<br />
                  <span className="font-normal text-zinc-200">{highlightedResource ? `${highlightedResource.employee_number}. ${highlightedResource.name}` : 'Brak'}</span>
                </th>
                {days.map((day) => (
                  <SelectedEmployeeDayCell
                    key={day}
                    info={selectedDayInfo(dateForDay(props.period, day), highlightedEmployeeNumber, props.assignments, props.absences)}
                  />
                ))}
              </tr>
            </tbody>
          </table>
        </div>

        <div className="mt-5 grid gap-4 lg:grid-cols-[1fr_420px]">
          <section className="bg-white p-4 ring-1 ring-zinc-200">
            <h3 className="mb-3 flex items-center gap-2 font-semibold"><Activity size={16} />Score components</h3>
            <div className="grid gap-2 md:grid-cols-2">
              {props.scoreComponents.map((component) => <div key={component.id} className="flex justify-between border border-zinc-200 px-3 py-2 text-sm"><span>{component.label}</span><b>{component.score}</b></div>)}
            </div>
          </section>
          <section className="bg-white p-4 ring-1 ring-zinc-200">
            <h3 className="mb-3 font-semibold">Naruszenia</h3>
            <div className="max-h-72 space-y-2 overflow-auto text-sm">
              {props.violations.length === 0 ? <p className="text-zinc-500">Brak naruszeń dla ostatniego wyniku.</p> : props.violations.map((v) => (
                <div key={v.id} className={`border p-2 ${violationStyle(v)}`}>
                  <b>{v.code}</b>
                  {v.employee_number && <p className="font-medium">{v.employee_number}. {v.resource_name}</p>}
                  <p>{v.message}</p>
                  {violationDetails(v) && <p className="text-xs">{violationDetails(v)}</p>}
                </div>
              ))}
            </div>
          </section>
        </div>

        <div className="mt-5 bg-white p-4 ring-1 ring-zinc-200">
          <h3 className="mb-3 font-semibold">Zasoby i nominały</h3>
          <div className="flex flex-col gap-2">
            {props.resources.map((r) => (
              <button
                key={r.id}
                type="button"
                onClick={() => setHighlightedEmployeeNumber(r.employee_number)}
                className={`grid gap-3 border p-3 text-left text-sm md:grid-cols-[280px_repeat(6,minmax(110px,1fr))] ${highlightedEmployeeNumber === r.employee_number ? 'border-fuchsia-400 bg-fuchsia-50' : 'border-zinc-200 bg-white'}`}
              >
                <div>
                  <b>{r.employee_number}. {r.name}</b>
                  <p className="mt-1 text-xs font-medium text-zinc-600">JPG: {r.planned_duties_note}</p>
                  {r.workload_policy === 'minimize_usage' && <span className="ml-2 rounded-sm border border-sky-300 bg-sky-50 px-1.5 py-0.5 text-[11px] font-medium text-sky-900">kontrakt / zlecenie</span>}
                  {!r.is_active && <p className="text-xs text-red-700">Nieaktywny</p>}
                </div>
                <ResourceStat label="Do wypracowania" value={r.workload_policy === 'minimize_usage' ? 'wg potrzeb' : minutes(r.remaining_work_minutes)} />
                <ResourceStat label="Nominał miesiąc" value={minutes(r.target_minutes_per_month)} />
                <ResourceStat label="Max czas" value={r.max_minutes_per_month ? minutes(r.max_minutes_per_month) : 'bez limitu'} />
                <ResourceStat label="Urlopy" value={minutes(r.planned_absence_minutes)} />
                <ResourceStat label="Zaplanowano" value={minutes(r.planned_work_minutes)} />
                <ResourceStat label="Praca + urlopy" value={minutes(r.planned_total_minutes)} />
              </button>
            ))}
          </div>
        </div>

        <div className="mt-5 bg-white p-4 ring-1 ring-zinc-200">
          <h3 className="mb-3 font-semibold">Absencje z JPG</h3>
          <div className="flex flex-wrap gap-2 text-sm">
            {props.absences.map((a, index) => <span key={index} className="rounded-sm border border-amber-300 bg-amber-50 px-2 py-1">{a.employee_number}. {a.resource_name}: {a.type_name}</span>)}
          </div>
        </div>

        <div className="mt-5 bg-white p-4 ring-1 ring-zinc-200">
          <h3 className="mb-3 font-semibold">Święta</h3>
          <div className="flex flex-wrap gap-2 text-sm">
            {props.holidays.length === 0 ? <span className="text-zinc-500">Brak świąt w bieżącym okresie.</span> : props.holidays.map((holiday) => <span key={`${holiday.holiday_date}-${holiday.scope}`} className="rounded-sm border border-rose-300 bg-rose-50 px-2 py-1">{holiday.holiday_date}: {holiday.name}</span>)}
          </div>
        </div>
      </section>
    </AppShell>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return <div className="bg-white p-4 ring-1 ring-zinc-200"><p className="text-xs uppercase text-zinc-500">{label}</p><p className="mt-1 text-2xl font-semibold">{value}</p></div>;
}

function ResourceStat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-[11px] uppercase text-zinc-500">{label}</p>
      <p className="font-semibold text-zinc-950">{value}</p>
    </div>
  );
}

function PlanningRuleRow({ rule }: { rule: PlanningRule }) {
  const [weight, setWeight] = useState(rule.weight.toString());
  const [isActive, setIsActive] = useState(rule.is_active);

  useEffect(() => {
    setWeight(rule.weight.toString());
    setIsActive(rule.is_active);
  }, [rule.weight, rule.is_active]);

  const save = (next: { is_active?: boolean; weight?: string }) => {
    const nextActive = next.is_active ?? isActive;
    const nextWeight = Math.max(0, Number.parseInt(next.weight ?? weight, 10) || 0);
    router.patch(`/planning-rules/${rule.code}`, { is_active: nextActive, weight: nextWeight }, { preserveScroll: true });
  };

  return (
    <div className={`grid grid-cols-[1fr_96px] gap-3 border p-3 text-sm ${isActive ? 'border-zinc-200 bg-white' : 'border-zinc-200 bg-zinc-50 text-zinc-500'}`}>
      <label className="flex min-w-0 items-start gap-2">
        <input
          type="checkbox"
          className="mt-1 h-4 w-4"
          checked={isActive}
          disabled={!rule.can_toggle}
          onChange={(event) => {
            if (!rule.can_toggle) return;
            setIsActive(event.target.checked);
            save({ is_active: event.target.checked });
          }}
        />
        <span className="min-w-0">
          <span className="block font-medium text-zinc-950">{rule.name}</span>
          <span className="mt-1 inline-flex rounded-sm border border-zinc-300 bg-zinc-50 px-1.5 py-0.5 text-[11px] uppercase text-zinc-600">{rule.type === 'standard' ? 'standardowa' : rule.type}</span>
          {!rule.can_toggle && <span className="ml-1 mt-1 inline-flex rounded-sm border border-red-300 bg-red-50 px-1.5 py-0.5 text-[11px] uppercase text-red-700">wymagana</span>}
        </span>
      </label>
      <label className="text-[11px] uppercase text-zinc-500">
        Waga
        <input
          type="number"
          min="0"
          value={weight}
          onChange={(event) => setWeight(event.target.value)}
          onBlur={() => save({ weight })}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.currentTarget.blur();
            }
          }}
          className="mt-1 w-full border border-zinc-300 px-2 py-1 text-right text-sm font-semibold text-zinc-950"
        />
      </label>
    </div>
  );
}

function SelectedEmployeeDayCell({ info }: { info: { type: string; label: string; title: string } }) {
  const color = info.type === 'work'
    ? 'border-emerald-300 bg-emerald-50 text-emerald-950'
    : info.type === 'absence'
      ? 'border-amber-300 bg-amber-50 text-amber-950'
      : 'border-zinc-300 bg-zinc-100 text-zinc-600';

  return (
    <td title={info.title} className={`border border-t-zinc-900 p-1 text-center text-[11px] font-semibold leading-tight ${color}`}>
      <span className="block max-w-14 truncate">{info.label}</span>
    </td>
  );
}

function ScheduleCell({ assignments, resources, highlightedEmployeeNumber, isHoliday, isActive, hasViolation, onActivate, emptyVariant = 'dash' }: { assignments: Assignment[]; resources: Resource[]; highlightedEmployeeNumber: number | null; isHoliday: boolean; isActive: boolean; hasViolation: boolean; onActivate: (assignmentId: number) => void; emptyVariant?: 'dash' | 'blank' }) {
  if (assignments.length === 0) return <td className={`border border-zinc-300 p-1 text-center text-zinc-400 ${emptyVariant === 'blank' ? 'bg-emerald-50/30' : isHoliday ? 'bg-rose-50' : 'bg-zinc-50'}`}>{emptyVariant === 'blank' ? '' : '-'}</td>;
  const assignment = assignments[0];
  const update = (resourceId: string) => router.patch(`/assignments/${assignment.id}`, { resource_id: resourceId || null }, { preserveScroll: true });
  const toggleLock = () => router.post(`/assignments/${assignment.id}/${assignment.is_locked ? 'unlock' : 'lock'}`, {}, { preserveScroll: true });
  const isHighlighted = assignments.some((item) => item.employee_number === highlightedEmployeeNumber);
  const isLocked = Boolean(assignment.is_locked);
  const isSplit = assignments.length > 1;
  const timeLabel = timeOnly(assignment.starts_at) !== timeOnly(assignment.slot_starts_at) ? `od ${timeOnly(assignment.starts_at)}` : '';
  const isPartial = Boolean(assignment.metadata?.segment_kind) || assignment.source.includes('top_up') || timeLabel !== '';
  const title = isSplit
    ? `Podzielony dyżur: ${assignments.map((item) => `${item.employee_number ?? '-'} ${timeRange(item)}`).join(', ')}`
    : 'Edytuj przypisanie';

  return (
    <td
      role="button"
      tabIndex={0}
      title={title}
      onClick={() => onActivate(assignment.id)}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          onActivate(assignment.id);
        }
      }}
      className={`cursor-pointer border p-1 align-top ${hasViolation ? 'border-red-600 ring-2 ring-inset ring-red-600' : 'border-zinc-300'} ${assignment.resource_id ? (isHighlighted ? 'bg-fuchsia-100' : isHoliday ? 'bg-rose-50' : 'bg-white') : 'bg-red-50'} ${isActive && !hasViolation ? 'ring-2 ring-inset ring-zinc-900' : ''}`}
    >
      <div className="flex min-h-12 flex-col justify-center gap-1">
        {isSplit ? (
          <div className={`grid grid-flow-col auto-cols-fr text-center font-semibold ${isHighlighted ? 'text-fuchsia-900' : 'text-zinc-900'}`}>
            {assignments.map((item) => (
              <span key={item.id} className="min-w-0">
                <span className="block h-3 truncate text-[9px] font-medium leading-3 text-zinc-500">{timeRange(item)}</span>
                <span className="block text-base leading-5">{item.employee_number ?? '-'}</span>
              </span>
            ))}
          </div>
        ) : (
          <div className={`text-center text-base font-semibold ${isHighlighted ? 'text-fuchsia-900' : 'text-zinc-900'}`}>
            {timeLabel && <span className="block h-3 truncate text-[9px] font-medium leading-3 text-zinc-500">{timeLabel}</span>}
            <span className="block leading-5">{assignment.employee_number ?? '-'}</span>
          </div>
        )}
        {(isSplit || isPartial) && <div className="text-center text-[10px] font-medium text-zinc-500">{assignments.map((item) => minutes(item.duration_minutes)).join(' / ')}</div>}
        {!isActive && isLocked && <Lock className="mx-auto text-zinc-600" size={12} />}
        {isActive && !isSplit && (
          <>
            <select
              autoFocus
              className={`w-full border text-center text-[11px] ${isHighlighted ? 'border-fuchsia-400 bg-fuchsia-50 text-fuchsia-950' : 'border-zinc-300 bg-white'}`}
              value={assignment.resource_id ?? ''}
              onClick={(event) => event.stopPropagation()}
              onChange={(event) => update(event.target.value)}
            >
              <option value="">Brak</option>
              {resources.filter((r) => r.is_active).map((r) => <option key={r.id} value={r.id}>{r.employee_number}</option>)}
            </select>
            <button
              title={isLocked ? 'Odblokuj' : 'Zablokuj'}
              onClick={(event) => {
                event.stopPropagation();
                toggleLock();
              }}
              className="inline-flex items-center justify-center border border-zinc-300 bg-white py-0.5"
            >
              {isLocked ? <Lock size={12} /> : <Unlock size={12} />}
            </button>
          </>
        )}
      </div>
    </td>
  );
}

createInertiaApp({
  resolve: (name) => ({ Schedule }[name] as React.ComponentType<any>),
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
});

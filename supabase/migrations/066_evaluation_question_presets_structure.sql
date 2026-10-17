-- Track the event layout a question preset was created for.
-- Existing presets are marked unknown because their original layout cannot be inferred safely.

alter table public.evaluation_question_presets
  add column if not exists event_structure text not null default 'unknown';

alter table public.evaluation_question_presets
  drop constraint if exists evaluation_question_presets_event_structure_check;

alter table public.evaluation_question_presets
  add constraint evaluation_question_presets_event_structure_check
  check (event_structure in ('simple', 'one_seminar', 'two_seminars', 'unknown'));

drop index if exists public.evaluation_question_presets_owner_name_idx;

create unique index evaluation_question_presets_owner_name_idx
  on public.evaluation_question_presets (created_by, event_structure, lower(preset_name));

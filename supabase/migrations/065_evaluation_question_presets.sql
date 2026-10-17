-- Teacher-owned reusable sets of event and seminar evaluation questions.
-- All reads and writes go through the PHP BFF using the service role.

create table if not exists public.evaluation_question_presets (
  id uuid primary key default gen_random_uuid(),
  created_by uuid not null references public.users(id) on delete cascade,
  event_structure text not null check (event_structure in ('simple', 'one_seminar', 'two_seminars', 'unknown')),
  preset_name varchar(80) not null check (length(btrim(preset_name)) > 0),
  questions jsonb not null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint evaluation_question_presets_questions_check check (
    case
      when jsonb_typeof(questions) = 'array'
        then jsonb_array_length(questions) between 1 and 50
      when jsonb_typeof(questions) = 'object' then
        case
          when jsonb_typeof(questions->'event_questions') = 'array'
            and jsonb_typeof(questions->'seminars') = 'array'
            then jsonb_array_length(questions->'event_questions') > 0
              or jsonb_array_length(questions->'seminars') > 0
          else false
        end
      else false
    end
  )
);

create unique index if not exists evaluation_question_presets_owner_name_idx
  on public.evaluation_question_presets (created_by, event_structure, lower(preset_name));

create index if not exists evaluation_question_presets_owner_updated_idx
  on public.evaluation_question_presets (created_by, updated_at desc);

alter table public.evaluation_question_presets enable row level security;

revoke all on table public.evaluation_question_presets from anon, authenticated;
grant all on table public.evaluation_question_presets to service_role;

do $$
begin
  if not exists (
    select 1 from pg_policies
    where schemaname = 'public'
      and tablename = 'evaluation_question_presets'
      and policyname = 'evaluation_question_presets_deny_clients'
  ) then
    create policy evaluation_question_presets_deny_clients
      on public.evaluation_question_presets
      for all
      using (false)
      with check (false);
  end if;
end $$;

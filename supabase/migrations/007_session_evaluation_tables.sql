-- CCS PulseConnect migration 007
-- Ensure seminar/session evaluation tables exist for seminar-based events.

create table if not exists public.event_session_evaluation_questions (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null references public.event_sessions(id) on delete cascade,
  question_text text not null,
  field_type text not null default 'text',
  required boolean not null default false,
  sort_order integer not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conname = 'event_session_evaluation_field_type_check'
  ) then
    alter table public.event_session_evaluation_questions
      add constraint event_session_evaluation_field_type_check
      check (field_type in ('text', 'rating'));
  end if;
end $$;

create index if not exists event_session_questions_session_idx
  on public.event_session_evaluation_questions(session_id);

grant all privileges on table public.event_session_evaluation_questions to anon, authenticated, service_role;

create table if not exists public.event_session_evaluation_answers (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null references public.event_sessions(id) on delete cascade,
  question_id uuid not null references public.event_session_evaluation_questions(id) on delete cascade,
  student_id uuid not null references public.users(id) on delete cascade,
  answer_text text,
  submitted_at timestamptz not null default now(),
  unique (question_id, student_id)
);

create index if not exists event_session_answers_session_idx
  on public.event_session_evaluation_answers(session_id);

create index if not exists event_session_answers_student_idx
  on public.event_session_evaluation_answers(student_id);

grant all privileges on table public.event_session_evaluation_answers to anon, authenticated, service_role;

-- Refresh PostgREST schema cache so the new tables/columns are visible immediately.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;


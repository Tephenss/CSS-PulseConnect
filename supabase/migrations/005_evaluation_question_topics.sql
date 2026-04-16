-- CCS PulseConnect migration 005
-- Add optional topic labels for event and seminar evaluation questions.

alter table public.evaluation_questions
  add column if not exists topic text;

do $$
begin
  if exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public'
      and c.relname = 'event_session_evaluation_questions'
  ) then
    alter table public.event_session_evaluation_questions
      add column if not exists topic text;
  end if;
end $$;

create index if not exists evaluation_questions_event_topic_idx
  on public.evaluation_questions(event_id, topic);

do $$
begin
  if exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public'
      and c.relname = 'event_session_evaluation_questions'
  ) then
    create index if not exists event_session_questions_session_topic_idx
      on public.event_session_evaluation_questions(session_id, topic);
  end if;
end $$;

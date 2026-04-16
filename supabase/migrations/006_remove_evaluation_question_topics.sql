-- CCS PulseConnect migration 006
-- Remove optional topic labels from evaluation question tables.

drop index if exists public.evaluation_questions_event_topic_idx;
drop index if exists public.event_session_questions_session_topic_idx;

alter table public.evaluation_questions
  drop column if exists topic;

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
      drop column if exists topic;
  end if;
end $$;


-- Ensure seminar sessions have a stable 1-based session_no (NOT NULL on live).
-- Safe to re-run.

alter table public.event_sessions
  add column if not exists session_no integer;

update public.event_sessions es
set session_no = ranked.n
from (
  select
    id,
    row_number() over (
      partition by event_id
      order by coalesce(sort_order, 0) asc, start_at asc, id asc
    ) as n
  from public.event_sessions
) ranked
where es.id = ranked.id
  and (es.session_no is null or es.session_no <= 0);

alter table public.event_sessions
  alter column session_no set default 1;

alter table public.event_sessions
  alter column session_no set not null;

-- 051: Drop write-open *_temp policies for attendance / tickets / evaluations
-- after Flutter routes those writes through PHP (mobile_scan_ticket / mobile_secure_write).
-- Keep SELECT for anon so scan-context / roster reads still work until moved next.
-- Events/assistants/certs/proposals temps remain until Phase B.

begin;

-- ---------------------------------------------------------------------------
-- attendance
-- ---------------------------------------------------------------------------
do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='attendance') then
    alter table public.attendance enable row level security;
    drop policy if exists attendance_write_temp on public.attendance;
    drop policy if exists attendance_select_temp on public.attendance;
    create policy attendance_select_temp
      on public.attendance for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.attendance from anon, authenticated;
    grant select on table public.attendance to anon, authenticated;
    grant all on table public.attendance to service_role;
  end if;
end $$;

-- ---------------------------------------------------------------------------
-- event_session_attendance
-- ---------------------------------------------------------------------------
do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_attendance') then
    alter table public.event_session_attendance enable row level security;
    drop policy if exists event_session_attendance_write_temp on public.event_session_attendance;
    drop policy if exists event_session_attendance_select_temp on public.event_session_attendance;
    create policy event_session_attendance_select_temp
      on public.event_session_attendance for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_session_attendance from anon, authenticated;
    grant select on table public.event_session_attendance to anon, authenticated;
    grant all on table public.event_session_attendance to service_role;
  end if;
end $$;

-- ---------------------------------------------------------------------------
-- tickets
-- ---------------------------------------------------------------------------
do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='tickets') then
    alter table public.tickets enable row level security;
    drop policy if exists tickets_write_temp on public.tickets;
    drop policy if exists tickets_select_temp on public.tickets;
    create policy tickets_select_temp
      on public.tickets for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.tickets from anon, authenticated;
    grant select on table public.tickets to anon, authenticated;
    grant all on table public.tickets to service_role;
  end if;
end $$;

-- ---------------------------------------------------------------------------
-- attendance_absence_reasons
-- ---------------------------------------------------------------------------
do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='attendance_absence_reasons') then
    alter table public.attendance_absence_reasons enable row level security;
    drop policy if exists attendance_absence_reasons_write_temp on public.attendance_absence_reasons;
    drop policy if exists attendance_absence_reasons_select_temp on public.attendance_absence_reasons;
    create policy attendance_absence_reasons_select_temp
      on public.attendance_absence_reasons for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.attendance_absence_reasons from anon, authenticated;
    grant select on table public.attendance_absence_reasons to anon, authenticated;
    grant all on table public.attendance_absence_reasons to service_role;
  end if;
end $$;

-- ---------------------------------------------------------------------------
-- evaluation_answers
-- ---------------------------------------------------------------------------
do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='evaluation_answers') then
    alter table public.evaluation_answers enable row level security;
    drop policy if exists evaluation_answers_write_temp on public.evaluation_answers;
    drop policy if exists evaluation_answers_select_temp on public.evaluation_answers;
    create policy evaluation_answers_select_temp
      on public.evaluation_answers for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.evaluation_answers from anon, authenticated;
    grant select on table public.evaluation_answers to anon, authenticated;
    grant all on table public.evaluation_answers to service_role;
  end if;
end $$;

-- ---------------------------------------------------------------------------
-- event_session_evaluation_answers
-- ---------------------------------------------------------------------------
do $$
begin
  if exists (select 1 from information_schema.tables where table_schema='public' and table_name='event_session_evaluation_answers') then
    alter table public.event_session_evaluation_answers enable row level security;
    drop policy if exists event_session_evaluation_answers_write_temp on public.event_session_evaluation_answers;
    drop policy if exists event_session_evaluation_answers_select_temp on public.event_session_evaluation_answers;
    create policy event_session_evaluation_answers_select_temp
      on public.event_session_evaluation_answers for select to anon, authenticated using (true);
    revoke insert, update, delete on table public.event_session_evaluation_answers from anon, authenticated;
    grant select on table public.event_session_evaluation_answers to anon, authenticated;
    grant all on table public.event_session_evaluation_answers to service_role;
  end if;
end $$;

-- Mutating RPC now called via PHP service role only.
do $$
begin
  if to_regprocedure('public.sync_closed_session_absences(uuid)') is not null then
    revoke execute on function public.sync_closed_session_absences(uuid) from public, anon, authenticated;
    grant execute on function public.sync_closed_session_absences(uuid) to service_role;
  end if;
end $$;

commit;

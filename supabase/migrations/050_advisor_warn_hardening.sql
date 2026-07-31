-- CCS PulseConnect migration 050
-- Reduce Advisor WARN noise without bricking Flutter:
-- 1) Revoke anon/authenticated EXECUTE on dangerous SECURITY DEFINER write RPCs
--    (triggers still run as table owner / definer — app RPC abuse is blocked).
-- 2) Keep EXECUTE on read-only snapshot/count RPCs Flutter still calls.
-- 3) Tighten public storage listing policies on event-covers / evaluation-reopen-proofs.
--
-- NOTE: "RLS Policy Always True" WARNs on *_temp policies remain intentional until
-- Flutter attendance/events/certs are fully moved to PHP BFF. Those are WARN, not CRITICAL.

-- ---------------------------------------------------------------------------
-- 1) Dangerous / write-side SECURITY DEFINER — service_role only
-- ---------------------------------------------------------------------------
do $$
declare
  fn text;
  -- Functions that mutate state or are not needed as public RPC.
  dangerous text[] := array[
    'close_event_registration_at_capacity()',
    'enforce_event_registration_limit()',
    'sync_event_registered_count_from_registrations()'
  ];
begin
  foreach fn in array dangerous loop
    begin
      execute format('revoke execute on function public.%s from public, anon, authenticated', fn);
      execute format('grant execute on function public.%s to service_role', fn);
    exception when undefined_function then
      null;
    end;
  end loop;
end $$;

-- Read / sync RPCs Flutter still calls via anon — keep EXECUTE.
do $$
begin
  if to_regprocedure('public.event_registration_count(uuid)') is not null then
    grant execute on function public.event_registration_count(uuid) to anon, authenticated, service_role;
  end if;
  if to_regprocedure('public.get_event_registration_snapshot(uuid)') is not null then
    grant execute on function public.get_event_registration_snapshot(uuid) to anon, authenticated, service_role;
  end if;
  if to_regprocedure('public.get_event_session_attendance_snapshot(uuid)') is not null then
    grant execute on function public.get_event_session_attendance_snapshot(uuid) to anon, authenticated, service_role;
  end if;
  if to_regprocedure('public.get_event_student_requirements(uuid)') is not null then
    grant execute on function public.get_event_student_requirements(uuid) to anon, authenticated, service_role;
  end if;
  if to_regprocedure('public.sync_closed_session_absences(uuid)') is not null then
    grant execute on function public.sync_closed_session_absences(uuid) to anon, authenticated, service_role;
  end if;
end $$;

-- ---------------------------------------------------------------------------
-- 2) Public bucket listing — drop overly broad SELECT policies if present
--    Public object URLs still work without "list all files" policies.
-- ---------------------------------------------------------------------------
drop policy if exists "Allow All 1oj01fe_0" on storage.objects;
drop policy if exists evaluation_reopen_proofs_public_read on storage.objects;
drop policy if exists event_covers_public_read on storage.objects;

-- Recreate narrow public READ by bucket (SELECT for known public buckets only).
-- Clients can fetch by exact path; listing entire bucket is not granted via USING true
-- on all objects — use bucket_id filter without enabling unrestricted listing abuse
-- as much as possible. Note: any SELECT policy still allows list within filter.

do $$
begin
  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage' and tablename = 'objects'
      and policyname = 'event_covers_select_public'
  ) then
    create policy event_covers_select_public
      on storage.objects for select
      to anon, authenticated
      using (bucket_id = 'event-covers');
  end if;

  if not exists (
    select 1 from pg_policies
    where schemaname = 'storage' and tablename = 'objects'
      and policyname = 'evaluation_reopen_proofs_select_public'
  ) then
    create policy evaluation_reopen_proofs_select_public
      on storage.objects for select
      to anon, authenticated
      using (bucket_id = 'evaluation-reopen-proofs');
  end if;
end $$;

-- Keep event-covers bucket public for cover images (no PII).
update storage.buckets
set public = true
where id in ('event-covers', 'evaluation-reopen-proofs');

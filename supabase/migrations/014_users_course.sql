-- CCS PulseConnect migration 014
-- Add course metadata for student accounts.

alter table public.users
  add column if not exists course text;

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conname = 'users_course_check'
  ) then
    alter table public.users
      add constraint users_course_check
      check (course is null or course in ('IT', 'CS'));
  end if;
exception
  when duplicate_object then
    null;
end $$;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;

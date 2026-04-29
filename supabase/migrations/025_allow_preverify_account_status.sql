-- CCS PulseConnect migration 025
-- Allow pre-verification status for app registrations.

do $$
begin
  if exists (
    select 1
    from pg_constraint
    where conname = 'users_account_status_check'
  ) then
    alter table public.users
      drop constraint users_account_status_check;
  end if;
exception
  when undefined_object then
    null;
end $$;

do $$
begin
  alter table public.users
    add constraint users_account_status_check
    check (account_status in ('preverify', 'pending', 'approved', 'rejected'));
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


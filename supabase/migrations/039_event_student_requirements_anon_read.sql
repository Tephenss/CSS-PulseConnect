-- Ensure student requirement tables are readable by the mobile app (anon/authenticated).

alter table if exists public.event_student_requirements disable row level security;
alter table if exists public.event_student_submissions disable row level security;
alter table if exists public.event_student_documents disable row level security;

grant select, insert, update, delete on table public.event_student_requirements to anon, authenticated, service_role;
grant select, insert, update, delete on table public.event_student_submissions to anon, authenticated, service_role;
grant select, insert, update, delete on table public.event_student_documents to anon, authenticated, service_role;

create or replace function public.get_event_student_requirements(p_event_id uuid)
returns table (
    id uuid,
    code text,
    label text,
    sort_order integer,
    created_at timestamptz
)
language sql
security definer
set search_path = public
stable
as $$
    select
        r.id,
        r.code,
        r.label,
        r.sort_order,
        r.created_at
    from public.event_student_requirements r
    where r.event_id = p_event_id
    order by r.sort_order asc, r.created_at asc;
$$;

grant execute on function public.get_event_student_requirements(uuid) to anon, authenticated, service_role;

-- If RLS gets re-enabled later, keep read access open for requirement metadata.
do $$
begin
    if not exists (
        select 1 from pg_policies
        where schemaname = 'public'
          and tablename = 'event_student_requirements'
          and policyname = 'event_student_requirements_public_read'
    ) then
        create policy event_student_requirements_public_read
            on public.event_student_requirements
            for select
            to anon, authenticated
            using (true);
    end if;

    if not exists (
        select 1 from pg_policies
        where schemaname = 'public'
          and tablename = 'event_student_submissions'
          and policyname = 'event_student_submissions_student_read'
    ) then
        create policy event_student_submissions_student_read
            on public.event_student_submissions
            for select
            to anon, authenticated
            using (true);
    end if;

    if not exists (
        select 1 from pg_policies
        where schemaname = 'public'
          and tablename = 'event_student_documents'
          and policyname = 'event_student_documents_student_read'
    ) then
        create policy event_student_documents_student_read
            on public.event_student_documents
            for select
            to anon, authenticated
            using (true);
    end if;
end $$;

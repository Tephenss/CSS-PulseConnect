-- Student registration document requirements (configured by event creator, submitted via student app)

create table if not exists public.event_student_requirements (
    id uuid primary key default gen_random_uuid(),
    event_id uuid not null references public.events(id) on delete cascade,
    code text not null,
    label text not null,
    sort_order integer not null default 0,
    created_by uuid references public.users(id) on delete set null,
    created_at timestamptz not null default now()
);

create index if not exists event_student_requirements_event_idx
    on public.event_student_requirements(event_id, sort_order);

create table if not exists public.event_student_submissions (
    id uuid primary key default gen_random_uuid(),
    event_id uuid not null references public.events(id) on delete cascade,
    student_id uuid not null references public.users(id) on delete cascade,
    status text not null default 'pending_review',
    submitted_at timestamptz,
    reviewed_at timestamptz,
    reviewed_by uuid references public.users(id) on delete set null,
    decline_reason text,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    unique (event_id, student_id)
);

do $$
begin
    if not exists (
        select 1
        from pg_constraint
        where conname = 'event_student_submissions_status_check'
    ) then
        alter table public.event_student_submissions
            add constraint event_student_submissions_status_check
            check (status in ('pending_review', 'approved', 'declined'));
    end if;
end $$;

create index if not exists event_student_submissions_event_idx
    on public.event_student_submissions(event_id, status, submitted_at desc);

create table if not exists public.event_student_documents (
    id uuid primary key default gen_random_uuid(),
    event_id uuid not null references public.events(id) on delete cascade,
    requirement_id uuid not null references public.event_student_requirements(id) on delete cascade,
    student_id uuid not null references public.users(id) on delete cascade,
    file_name text not null,
    file_path text not null,
    file_url text,
    mime_type text,
    uploaded_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    unique (requirement_id, student_id)
);

create index if not exists event_student_documents_event_idx
    on public.event_student_documents(event_id, student_id);

grant all privileges on table public.event_student_requirements to anon, authenticated, service_role;
grant all privileges on table public.event_student_submissions to anon, authenticated, service_role;
grant all privileges on table public.event_student_documents to anon, authenticated, service_role;

alter table if exists public.event_student_requirements disable row level security;
alter table if exists public.event_student_submissions disable row level security;
alter table if exists public.event_student_documents disable row level security;

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
    'student-documents',
    'student-documents',
    true,
    10485760,
    array['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
)
on conflict (id) do nothing;

do $$
begin
    if not exists (
        select 1 from pg_policies
        where schemaname = 'storage'
          and tablename = 'objects'
          and policyname = 'student_documents_read'
    ) then
        create policy student_documents_read
            on storage.objects
            for select
            to anon, authenticated
            using (bucket_id = 'student-documents');
    end if;

    if not exists (
        select 1 from pg_policies
        where schemaname = 'storage'
          and tablename = 'objects'
          and policyname = 'student_documents_insert'
    ) then
        create policy student_documents_insert
            on storage.objects
            for insert
            to anon, authenticated
            with check (bucket_id = 'student-documents');
    end if;

    if not exists (
        select 1 from pg_policies
        where schemaname = 'storage'
          and tablename = 'objects'
          and policyname = 'student_documents_update'
    ) then
        create policy student_documents_update
            on storage.objects
            for update
            to anon, authenticated
            using (bucket_id = 'student-documents')
            with check (bucket_id = 'student-documents');
    end if;

    if not exists (
        select 1 from pg_policies
        where schemaname = 'storage'
          and tablename = 'objects'
          and policyname = 'student_documents_delete'
    ) then
        create policy student_documents_delete
            on storage.objects
            for delete
            to anon, authenticated
            using (bucket_id = 'student-documents');
    end if;
end $$;

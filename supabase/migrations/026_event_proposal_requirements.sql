-- Event proposal requirements workflow

alter table public.events
    add column if not exists proposal_stage text not null default 'pending_requirements';

alter table public.events
    add column if not exists requirements_requested_at timestamptz;

alter table public.events
    add column if not exists requirements_submitted_at timestamptz;

do $$
begin
    if not exists (
        select 1
        from pg_constraint
        where conname = 'events_proposal_stage_check'
    ) then
        alter table public.events
            add constraint events_proposal_stage_check
            check (proposal_stage in ('pending_requirements', 'requirements_requested', 'under_review', 'approved'));
    end if;
end $$;

create table if not exists public.event_proposal_requirements (
    id uuid primary key default gen_random_uuid(),
    event_id uuid not null references public.events(id) on delete cascade,
    code text not null,
    label text not null,
    sort_order integer not null default 0,
    created_by uuid references public.users(id) on delete set null,
    created_at timestamptz not null default now()
);

create index if not exists event_proposal_requirements_event_idx
    on public.event_proposal_requirements(event_id, sort_order);

create table if not exists public.event_proposal_documents (
    id uuid primary key default gen_random_uuid(),
    event_id uuid not null references public.events(id) on delete cascade,
    requirement_id uuid not null references public.event_proposal_requirements(id) on delete cascade,
    teacher_id uuid not null references public.users(id) on delete cascade,
    file_name text not null,
    file_path text not null,
    file_url text,
    mime_type text,
    uploaded_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    unique (requirement_id, teacher_id)
);

create index if not exists event_proposal_documents_event_idx
    on public.event_proposal_documents(event_id, teacher_id);

grant all privileges on table public.event_proposal_requirements to anon, authenticated, service_role;
grant all privileges on table public.event_proposal_documents to anon, authenticated, service_role;

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values (
    'proposal-documents',
    'proposal-documents',
    true,
    10485760,
    array['image/jpeg', 'image/png', 'image/webp']
)
on conflict (id) do nothing;

do $$
begin
    if not exists (
        select 1 from pg_policies
        where schemaname = 'storage'
          and tablename = 'objects'
          and policyname = 'proposal_documents_read'
    ) then
        create policy proposal_documents_read
            on storage.objects
            for select
            to anon, authenticated
            using (bucket_id = 'proposal-documents');
    end if;

    if not exists (
        select 1 from pg_policies
        where schemaname = 'storage'
          and tablename = 'objects'
          and policyname = 'proposal_documents_insert'
    ) then
        create policy proposal_documents_insert
            on storage.objects
            for insert
            to anon, authenticated
            with check (bucket_id = 'proposal-documents');
    end if;

    if not exists (
        select 1 from pg_policies
        where schemaname = 'storage'
          and tablename = 'objects'
          and policyname = 'proposal_documents_update'
    ) then
        create policy proposal_documents_update
            on storage.objects
            for update
            to anon, authenticated
            using (bucket_id = 'proposal-documents')
            with check (bucket_id = 'proposal-documents');
    end if;

    if not exists (
        select 1 from pg_policies
        where schemaname = 'storage'
          and tablename = 'objects'
          and policyname = 'proposal_documents_delete'
    ) then
        create policy proposal_documents_delete
            on storage.objects
            for delete
            to anon, authenticated
            using (bucket_id = 'proposal-documents');
    end if;
end $$;

-- Keep teacher proposal uploads private until the teacher submits them for review.
-- Existing submitted uploads remain visible to admin even if new requirements are added later.

alter table public.event_proposal_documents
    add column if not exists admin_visible boolean not null default false;

alter table public.event_proposal_documents
    add column if not exists visible_at timestamptz;

create index if not exists event_proposal_documents_visible_idx
    on public.event_proposal_documents(event_id, admin_visible, teacher_id);

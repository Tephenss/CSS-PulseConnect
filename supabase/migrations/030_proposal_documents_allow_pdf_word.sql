-- Allow proposal requirement uploads to accept document formats, not just images.

update storage.buckets
set allowed_mime_types = array[
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
]
where id = 'proposal-documents';

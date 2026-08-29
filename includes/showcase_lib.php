<?php
declare(strict_types=1);

const SHOWCASE_SLIDES_BUCKET = 'showcase-slides';
const SHOWCASE_MAX_ACTIVE_SLIDES = 8;
const SHOWCASE_MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

function showcase_service_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function showcase_write_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function showcase_public_url(string $path): string
{
    $segments = array_map(
        'rawurlencode',
        array_filter(explode('/', $path), static fn($part): bool => $part !== '')
    );
    return rtrim(SUPABASE_URL, '/') . '/storage/v1/object/public/'
        . SHOWCASE_SLIDES_BUCKET . '/' . implode('/', $segments);
}

function showcase_extension(string $mimeType): string
{
    return match (strtolower(trim($mimeType))) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
    };
}

/**
 * @return array{ok:bool,slides:array<int,array<string,mixed>>,version:string,error?:string}
 */
function showcase_fetch_active_slides(): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
        . '?select=id,label,image_url,sort_order,updated_at,is_active'
        . '&is_active=eq.true'
        . '&order=sort_order.asc,created_at.asc'
        . '&limit=' . SHOWCASE_MAX_ACTIVE_SLIDES;
    $res = supabase_request('GET', $url, showcase_service_headers());
    if (!$res['ok']) {
        return [
            'ok' => false,
            'slides' => [],
            'version' => '',
            'error' => 'Unable to load showcase slides.',
        ];
    }

    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        $rows = [];
    }

    $slides = [];
    $versionParts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $imageUrl = trim((string) ($row['image_url'] ?? ''));
        if ($id === '' || $imageUrl === '') {
            continue;
        }
        $updatedAt = trim((string) ($row['updated_at'] ?? ''));
        $slides[] = [
            'id' => $id,
            'label' => trim((string) ($row['label'] ?? '')),
            'image_url' => $imageUrl,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'updated_at' => $updatedAt,
        ];
        $versionParts[] = $id . ':' . $updatedAt;
    }

    return [
        'ok' => true,
        'slides' => $slides,
        'version' => sha1(implode('|', $versionParts)),
    ];
}

function showcase_count_active_slides(): int
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
        . '?select=id'
        . '&is_active=eq.true';
    $headers = showcase_service_headers();
    $headers[] = 'Prefer: count=exact';
    $headers[] = 'Range: 0-0';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        return 0;
    }
    $range = (string) ($res['headers']['content-range'] ?? $res['headers']['Content-Range'] ?? '');
    if (preg_match('/\/(\d+)$/', $range, $m)) {
        return (int) $m[1];
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return is_array($rows) ? count($rows) : 0;
}

function showcase_delete_storage_object(string $storagePath): void
{
    $storagePath = trim($storagePath);
    if ($storagePath === '') {
        return;
    }
    $segments = array_map('rawurlencode', explode('/', $storagePath));
    $storageUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/'
        . SHOWCASE_SLIDES_BUCKET . '/' . implode('/', $segments);
    supabase_request('DELETE', $storageUrl, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);
}

/**
 * @return array<int,array<string,mixed>>
 */
function showcase_default_fallback_slides(): array
{
    return [
        [
            'id' => 'default-summit',
            'label' => 'CCS SUMMIT',
            'image_url' => '/assets/sample summit/image1.jpg',
            'sort_order' => 0,
            'updated_at' => '',
        ],
        [
            'id' => 'default-ga',
            'label' => 'GENERAL ASSEMBLY',
            'image_url' => '/assets/sample GA/image1.jpg',
            'sort_order' => 1,
            'updated_at' => '',
        ],
        [
            'id' => 'default-exhibit',
            'label' => 'CCS EXHIBIT',
            'image_url' => '/assets/sample exhibit/image1.jpg',
            'sort_order' => 2,
            'updated_at' => '',
        ],
        [
            'id' => 'default-cv',
            'label' => 'COMPANY VISIT',
            'image_url' => '/assets/sample CV/image1.jpg',
            'sort_order' => 3,
            'updated_at' => '',
        ],
    ];
}

<?php

declare(strict_types=1);

namespace Reedapt;

/**
 * Documented array shapes for what the API returns and accepts. The SDK passes
 * the API's JSON through as arrays, so a field Reedapt adds tomorrow is
 * available today; treat unknown keys as normal.
 *
 * @phpstan-type UploadStatus 'uploading'|'fetching'|'uploaded'|'used'|'failed'
 * @phpstan-type PartLink array{part_number: int, url: string}
 * @phpstan-type Upload array{id: string, filename: string, size: int, status: UploadStatus, error?: string|null, part_size: int, part_count: int, parts?: list<PartLink>, parts_expire_at?: string|null}
 * @phpstan-type UploadedPart array{part_number: int, etag: string}
 * @phpstan-type DubStatus 'queued'|'processing'|'completed'|'failed'
 * @phpstan-type DubError array{code: string, message: string}
 * @phpstan-type Dub array{id: string, status: DubStatus, stage?: string|null, progress: int|float, source_language: string|null, target_language: string|null, audio: bool, test: bool, preview_seconds?: int|null, duration_minutes?: int|float|null, created_at: string, started_at?: string|null, completed_at?: string|null, error?: DubError|null, metadata?: array<string, string>}
 * @phpstan-type RefusedLanguage array{target_language: string, code: string, message: string}
 * @phpstan-type DubsCreated array{dubs: list<Dub>, refused?: list<RefusedLanguage>}
 * @phpstan-type DubList array{data: list<Dub>, has_more: bool}
 * @phpstan-type FileLink array{kind: 'dub'|'dubbed_audio_wav'|'voice'|'background', filename: string, url: string}
 * @phpstan-type DubFiles array{files: list<FileLink>, expires_at: string}
 * @phpstan-type TranscriptLine array{start_ms: int, end_ms: int, speaker?: string|null, original_text: string, text: string}
 * @phpstan-type Transcript array{dub_id: string, source_language?: string|null, target_language?: string|null, lines: list<TranscriptLine>}
 * @phpstan-type FlaggedLine array{line: int, start_ms: int, checks: list<string>, fixed: 'shortened'|'revoiced'|null}
 * @phpstan-type Quality array{dub_id: string, checked: bool, listened: bool, lines: int, fixed: int, to_review: int, flagged: list<FlaggedLine>}
 * @phpstan-type RunStatus 'done'|'needs_input'|'applied'|'declined'|'failed'
 * @phpstan-type RunProposal array{kind: string, summary: string, lines: int}
 * @phpstan-type RunOption array{id: 'apply'|'decline', label: string}
 * @phpstan-type Run array{id: string, dub_id: string, status: RunStatus, request: string, reply: string, proposal?: RunProposal|null, options?: list<RunOption>|null, applied_lines?: int|null, created_at: string}
 * @phpstan-type DubLine array{line: int, start_ms: int, end_ms: int, speaker?: string|null, original_text: string, text: string, delivery_style?: string|null, voice_instructions?: string|null}
 * @phpstan-type DubLineList array{dub_id: string, lines: list<DubLine>}
 * @phpstan-type LineEdit array{line: int, text?: string, delivery_style?: string|null, voice_instructions?: string|null}
 * @phpstan-type LinesEdited array{dub_id: string, lines: list<int>, status: 'revoicing'}
 * @phpstan-type Languages array{target_languages: list<string>}
 * @phpstan-type Usage array{plan?: string|null, key_mode: 'live'|'test', minutes_remaining?: int|float|null, unlimited: bool, test_preview_seconds: int}
 * @phpstan-type DubCounts array{completed: int, in_progress: int, failed: int}
 * @phpstan-type UsageCounts array{minutes: int|float, minutes_in_progress: int|float, dubs: DubCounts}
 * @phpstan-type UsageReport array{month: string, period_start: string, period_end: string, minutes: int|float, minutes_in_progress: int|float, dubs: DubCounts, keys: list<array<string, mixed>>, languages: list<array<string, mixed>>}
 * @phpstan-type WebhookEndpoint array{id: string, url: string, description?: string|null, events: list<string>, created_at: string, secret?: string|null}
 * @phpstan-type WebhookEndpointList array{data: list<WebhookEndpoint>}
 * @phpstan-type WebhookDelivery array{id: string, event_id: string, event_type: string, status: string, attempts: int, last_status_code?: int|null, last_error?: string|null, next_attempt_at?: string|null, created_at: string, delivered_at?: string|null}
 * @phpstan-type WebhookDeliveryList array{data: list<WebhookDelivery>}
 * @phpstan-type WebhookEvent array{id: string, type: string, created_at: string, data: array<string, mixed>}
 * @phpstan-type CreateDubParams array{upload_id: string, source_language: string, target_languages: list<string>, voice_cloning?: bool, num_speakers?: int, media_category?: string, instructions?: string, metadata?: array<string, string>}
 * @phpstan-type RequestOptions array{idempotency_key?: string, max_retries?: int, headers?: array<string, string>}
 */
final class Types
{
    private function __construct()
    {
    }
}

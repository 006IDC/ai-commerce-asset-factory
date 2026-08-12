<?php

declare(strict_types=1);

namespace app\service;

use app\support\Web;
use think\facade\Db;

final class CreativeProjectService
{
    public const SCHEMA_VERSION = 3;
    private const MAX_DOCUMENT_BYTES = 2_000_000;
    private const MAX_REVISIONS = 60;

    public function openCanvasProject(
        int $merchantId,
        int $userId,
        int $requestedProjectId = 0,
        bool $startNew = false,
        int $selectedAssetId = 0
    ): array {
        $project = null;
        if ($startNew) {
            $project = $this->createProject(
                $merchantId,
                $userId,
                '未命名画布 ' . date('m-d H:i'),
                'image',
                null,
                $this->defaultDocument('design')
            );
        } elseif ($requestedProjectId > 0) {
            $project = $this->findOwnedProject($merchantId, $requestedProjectId);
            if (!$project) {
                throw new \RuntimeException('创作项目不存在或无权访问。');
            }
        } else {
            $project = Db::name('creative_projects')
                ->where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->order('updated_at', 'desc')
                ->order('id', 'desc')
                ->find();
        }

        if (!$project) {
            $legacy = Db::name('canvas_workspaces')->where('merchant_id', $merchantId)->find();
            if ($legacy) {
                $legacyGraph = json_decode((string) ($legacy['graph_json'] ?? ''), true);
                $legacyDocument = $this->pruneUnownedAssetReferences(
                    $this->documentFromLegacyGraph(is_array($legacyGraph) ? $legacyGraph : []),
                    $merchantId
                );
                $project = $this->createProject(
                    $merchantId,
                    $userId,
                    '历史画布',
                    'image',
                    null,
                    $legacyDocument,
                    true
                );
            } else {
                $project = $this->createProject(
                    $merchantId,
                    $userId,
                    '我的第一张画布',
                    'image',
                    null,
                    $this->defaultDocument($selectedAssetId > 0 ? 'board' : 'design')
                );
            }
        }

        $document = $this->decodeDocument((string) $project['document_json']);
        if ($selectedAssetId > 0) {
            $asset = Db::name('assets')
                ->where('id', $selectedAssetId)
                ->where('merchant_id', $merchantId)
                ->whereIn('status', ['active', 'pending'])
                ->find();
            if (!$asset) {
                throw new \RuntimeException('要加入画布的素材不存在或无权访问。');
            }
            [$document, $nodeUuid, $changed] = $this->withAssetNode($document, $selectedAssetId);
            if ($changed) {
                $saved = $this->saveDocument(
                    $merchantId,
                    $userId,
                    (int) $project['id'],
                    (int) $project['document_version'],
                    $document,
                    'auto'
                );
                $project = $saved['project'];
                $document = $saved['document'];
                $this->attachAsset($merchantId, (int) $project['id'], $selectedAssetId, $nodeUuid, 'source');
            }
        }

        $project['document'] = $document;
        return $project;
    }

    public function createProject(
        int $merchantId,
        int $userId,
        string $title,
        string $projectType = 'image',
        ?int $skuId = null,
        ?array $document = null,
        bool $legacyMigrated = false
    ): array {
        $title = mb_strimwidth(trim($title), 0, 120, '');
        if ($title === '') {
            $title = '未命名创作';
        }
        if (!in_array($projectType, ['image', 'video', 'mixed'], true)) {
            $projectType = 'image';
        }
        if ($skuId !== null && $skuId > 0) {
            $ownedSku = Db::name('skus')->where('id', $skuId)->where('merchant_id', $merchantId)->where('status', '<>', 'deleted')->find();
            if (!$ownedSku) {
                throw new \RuntimeException('关联商品不存在或无权访问。');
            }
        } else {
            $skuId = null;
        }

        $normalized = $this->normalizeDocument($document ?? $this->defaultDocument('design'), $merchantId);
        $json = $this->encodeDocument($normalized);
        $hash = hash('sha256', $json);
        $now = Web::now();
        $projectId = Db::transaction(function () use ($merchantId, $userId, $skuId, $title, $projectType, $json, $hash, $legacyMigrated, $now): int {
            $projectId = (int) Db::name('creative_projects')->insertGetId([
                'merchant_id' => $merchantId,
                'created_by_user_id' => $userId > 0 ? $userId : null,
                'sku_id' => $skuId,
                'title' => $title,
                'project_type' => $projectType,
                'status' => 'active',
                'cover_asset_id' => null,
                'document_json' => $json,
                'document_hash' => $hash,
                'document_version' => 1,
                'current_revision_no' => 1,
                'legacy_canvas_migrated' => $legacyMigrated ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('creative_project_revisions')->insert([
                'project_id' => $projectId,
                'revision_no' => 1,
                'schema_version' => self::SCHEMA_VERSION,
                'source' => $legacyMigrated ? 'migration' : 'manual',
                'document_hash' => $hash,
                'document_json' => $json,
                'created_by_user_id' => $userId > 0 ? $userId : null,
                'created_at' => $now,
            ]);
            return $projectId;
        });

        $project = $this->findOwnedProject($merchantId, $projectId);
        if (!$project) {
            throw new \RuntimeException('创作项目创建失败。');
        }
        $this->syncAssetLinks($merchantId, $projectId, $normalized);
        $this->mirrorLegacyWorkspace($merchantId, $normalized);
        $project['document'] = $normalized;
        return $project;
    }

    public function saveDocument(
        int $merchantId,
        int $userId,
        int $projectId,
        int $expectedVersion,
        array $document,
        string $source = 'auto'
    ): array {
        $source = in_array($source, ['auto', 'manual', 'migration', 'export'], true) ? $source : 'auto';
        $normalized = $this->normalizeDocument($document, $merchantId);
        $json = $this->encodeDocument($normalized);
        $hash = hash('sha256', $json);
        $projectType = $this->projectTypeForDocument($normalized);
        $now = Web::now();

        $saved = Db::transaction(function () use ($merchantId, $userId, $projectId, $expectedVersion, $normalized, $json, $hash, $projectType, $source, $now): array {
            $project = Db::name('creative_projects')
                ->where('id', $projectId)
                ->where('merchant_id', $merchantId)
                ->where('status', 'active')
                ->lock(true)
                ->find();
            if (!$project) {
                throw new \RuntimeException('创作项目不存在或已归档。');
            }
            if ($expectedVersion > 0 && (int) $project['document_version'] !== $expectedVersion) {
                throw new \RuntimeException('项目已在另一个页面更新，请刷新后继续，系统没有覆盖较新的内容。', 409);
            }
            if (hash_equals((string) $project['document_hash'], $hash)) {
                $project['document'] = $this->decodeDocument((string) $project['document_json']);
                return ['project' => $project, 'document' => $project['document'], 'unchanged' => true];
            }

            $revisionNo = (int) $project['current_revision_no'] + 1;
            $documentVersion = (int) $project['document_version'] + 1;
            $assetIds = $this->assetIdsFromDocument($normalized);
            $coverAssetId = $assetIds === [] ? null : (int) end($assetIds);
            Db::name('creative_projects')->where('id', $projectId)->update([
                'cover_asset_id' => $coverAssetId,
                'project_type' => $projectType,
                'document_json' => $json,
                'document_hash' => $hash,
                'document_version' => $documentVersion,
                'current_revision_no' => $revisionNo,
                'updated_at' => $now,
            ]);
            Db::name('creative_project_revisions')->insert([
                'project_id' => $projectId,
                'revision_no' => $revisionNo,
                'schema_version' => self::SCHEMA_VERSION,
                'source' => $source,
                'document_hash' => $hash,
                'document_json' => $json,
                'created_by_user_id' => $userId > 0 ? $userId : null,
                'created_at' => $now,
            ]);
            $project = array_merge($project, [
                'cover_asset_id' => $coverAssetId,
                'project_type' => $projectType,
                'document_json' => $json,
                'document_hash' => $hash,
                'document_version' => $documentVersion,
                'current_revision_no' => $revisionNo,
                'updated_at' => $now,
                'document' => $normalized,
            ]);
            return ['project' => $project, 'document' => $normalized, 'unchanged' => false];
        });

        if (!$saved['unchanged']) {
            $this->syncAssetLinks($merchantId, $projectId, $normalized);
            $this->mirrorLegacyWorkspace($merchantId, $normalized);
            $this->pruneRevisions($projectId);
        }
        return $saved;
    }

    public function attachAsset(
        int $merchantId,
        int $projectId,
        int $assetId,
        string $nodeUuid,
        string $role = 'source'
    ): void {
        $project = $this->findOwnedProject($merchantId, $projectId);
        $asset = Db::name('assets')->where('id', $assetId)->where('merchant_id', $merchantId)->find();
        if (!$project || !$asset) {
            throw new \RuntimeException('项目或素材不存在，无法建立关联。');
        }
        $nodeUuid = $this->normalizeUuid($nodeUuid, 'asset');
        $role = in_array($role, ['source', 'reference', 'design', 'output', 'video', 'audio'], true) ? $role : 'source';
        $existing = Db::name('creative_project_assets')->where('project_id', $projectId)->where('node_uuid', $nodeUuid)->find();
        if ($existing) {
            Db::name('creative_project_assets')->where('id', (int) $existing['id'])->update([
                'asset_id' => $assetId,
                'role' => $role,
            ]);
            return;
        }
        Db::name('creative_project_assets')->insert([
            'project_id' => $projectId,
            'asset_id' => $assetId,
            'node_uuid' => $nodeUuid,
            'role' => $role,
            'created_at' => Web::now(),
        ]);
    }

    public function registerRun(
        int $merchantId,
        int $projectId,
        int $jobId,
        string $runType,
        ?string $nodeUuid,
        array $inputSnapshot
    ): void {
        if (!$this->findOwnedProject($merchantId, $projectId)) {
            throw new \RuntimeException('创作项目不存在或无权访问。');
        }
        $runType = in_array($runType, ['image_edit', 'image_export', 'video_scene', 'video_export'], true) ? $runType : 'image_edit';
        $json = json_encode($inputSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = Web::now();
        $existing = Db::name('creative_project_runs')->where('job_id', $jobId)->find();
        if ($existing) {
            return;
        }
        Db::name('creative_project_runs')->insert([
            'project_id' => $projectId,
            'node_uuid' => $nodeUuid === null || $nodeUuid === '' ? null : $this->normalizeUuid($nodeUuid, 'run'),
            'job_id' => $jobId,
            'run_type' => $runType,
            'status' => 'queued',
            'input_snapshot_json' => is_string($json) ? $json : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateRunStatus(int $jobId, string $status): void
    {
        if (!in_array($status, ['queued', 'running', 'manual_review', 'completed', 'partial', 'failed', 'result_missing', 'cancelled'], true)) {
            return;
        }
        Db::name('creative_project_runs')->where('job_id', $jobId)->update([
            'status' => $status,
            'updated_at' => Web::now(),
        ]);
    }

    public function attachRunOutput(int $jobId, int $assetId, string $role = 'output'): void
    {
        $run = Db::name('creative_project_runs')->alias('r')
            ->join('creative_projects p', 'p.id=r.project_id')
            ->field('r.project_id,r.node_uuid,p.merchant_id')
            ->where('r.job_id', $jobId)
            ->find();
        if (!$run || $assetId <= 0) {
            return;
        }
        $nodeUuid = trim((string) ($run['node_uuid'] ?? ''));
        $this->attachAsset(
            (int) $run['merchant_id'],
            (int) $run['project_id'],
            $assetId,
            ($nodeUuid !== '' ? $nodeUuid : 'run-' . $jobId) . '-output',
            $role
        );
    }

    public function storyboardRuntime(int $merchantId, int $projectId): array
    {
        $project = $this->findOwnedProject($merchantId, $projectId);
        if (!$project) {
            throw new \RuntimeException('创作项目不存在或无权访问。');
        }
        $document = $this->decodeDocument((string) $project['document_json']);
        $scenes = is_array($document['storyboard']['scenes'] ?? null)
            ? array_values($document['storyboard']['scenes'])
            : [];
        $sceneIds = array_values(array_filter(array_map(
            static fn (array $scene): string => (string) ($scene['id'] ?? ''),
            $scenes
        )));
        $runs = Db::name('creative_project_runs')->alias('r')
            ->join('generation_jobs j', 'j.id=r.job_id')
            ->leftJoin('video_generation_requests v', 'v.job_id=j.id')
            ->field('r.id,r.node_uuid,r.job_id,r.status AS run_status,r.input_snapshot_json,r.created_at AS run_created_at,r.updated_at AS run_updated_at,j.status,j.progress_current,j.progress_total,j.progress_stage,j.progress_message,j.error_message,j.estimated_credits,j.actual_credits,j.model,j.new_api_request_id,j.created_at,v.provider_status,v.provider_progress,v.last_frame_url,v.duration_seconds')
            ->where('r.project_id', $projectId)
            ->where('r.run_type', 'video_scene')
            ->order('r.id', 'desc')
            ->select()
            ->toArray();
        $jobIds = array_values(array_unique(array_map(static fn (array $run): int => (int) $run['job_id'], $runs)));
        $assetsByJob = [];
        if ($jobIds !== []) {
            foreach (Db::name('assets')
                ->field('id,job_id,file_url,backup_file_url,poster_url,duration_seconds,status,created_at')
                ->where('merchant_id', $merchantId)
                ->where('media_type', 'video')
                ->whereIn('job_id', $jobIds)
                ->order('id', 'desc')
                ->select()
                ->toArray() as $asset) {
                $jobId = (int) $asset['job_id'];
                if (!isset($assetsByJob[$jobId]) && (string) $asset['status'] === 'active' && trim((string) $asset['file_url']) !== '') {
                    $assetsByJob[$jobId] = $asset;
                }
            }
        }

        $latestByScene = [];
        foreach ($runs as $run) {
            $sceneId = (string) ($run['node_uuid'] ?? '');
            if ($sceneId === '' || isset($latestByScene[$sceneId])) {
                continue;
            }
            $jobId = (int) $run['job_id'];
            $asset = $assetsByJob[$jobId] ?? null;
            $status = (string) $run['status'];
            if ($status === 'completed' && !$asset) {
                $status = 'result_missing';
            }
            $this->updateRunStatus($jobId, $status);
            if ($asset) {
                $this->attachAsset($merchantId, $projectId, (int) $asset['id'], $sceneId . '-output', 'video');
            }
            $progressTotal = max(1, (int) ($run['progress_total'] ?? 100));
            $progress = in_array($status, ['completed'], true)
                ? 100
                : max(0, min(99, (int) round((int) ($run['progress_current'] ?? 0) * 100 / $progressTotal)));
            $latestByScene[$sceneId] = [
                'sceneId' => $sceneId,
                'jobId' => $jobId,
                'status' => $status,
                'statusLabel' => job_status_label($status),
                'progress' => $progress,
                'stage' => (string) ($run['progress_stage'] ?? ''),
                'message' => trim((string) ($run['progress_message'] ?? '')) ?: job_status_label($status),
                'error' => localized_job_error((string) ($run['error_message'] ?? '')),
                'estimatedCredits' => max(0, (int) ($run['estimated_credits'] ?? 0)),
                'actualCredits' => max(0, (int) ($run['actual_credits'] ?? 0)),
                'model' => (string) ($run['model'] ?? ''),
                'requestId' => (string) ($run['new_api_request_id'] ?? ''),
                'providerStatus' => (string) ($run['provider_status'] ?? ''),
                'providerProgress' => max(0, min(100, (int) ($run['provider_progress'] ?? 0))),
                'durationSeconds' => max(0, (int) ($run['duration_seconds'] ?? 0)),
                'lastFrameUrl' => (string) ($run['last_frame_url'] ?? ''),
                'createdAt' => (string) ($run['created_at'] ?? ''),
                'asset' => $asset ? [
                    'id' => (int) $asset['id'],
                    'fileUrl' => (string) $asset['file_url'],
                    'backupFileUrl' => (string) ($asset['backup_file_url'] ?? ''),
                    'posterUrl' => (string) ($asset['poster_url'] ?? ''),
                    'durationSeconds' => max(0, (int) ($asset['duration_seconds'] ?? 0)),
                    'createdAt' => (string) ($asset['created_at'] ?? ''),
                ] : null,
            ];
        }

        $summary = [
            'total' => count($scenes),
            'draft' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'progress' => 0,
            'estimatedCredits' => 0,
            'actualCredits' => 0,
        ];
        $progressTotal = 0;
        foreach ($sceneIds as $sceneId) {
            $runtime = $latestByScene[$sceneId] ?? null;
            if (!$runtime) {
                $summary['draft']++;
                continue;
            }
            $status = (string) $runtime['status'];
            if ($status === 'completed') {
                $summary['completed']++;
            } elseif (in_array($status, ['failed', 'partial', 'result_missing', 'cancelled'], true)) {
                $summary['failed']++;
            } elseif ($status === 'running') {
                $summary['running']++;
            } else {
                $summary['queued']++;
            }
            $progressTotal += (int) $runtime['progress'];
            $summary['estimatedCredits'] += (int) $runtime['estimatedCredits'];
            $summary['actualCredits'] += (int) $runtime['actualCredits'];
        }
        $summary['progress'] = $summary['total'] > 0
            ? max(0, min(100, (int) round($progressTotal / $summary['total'])))
            : 0;

        return [
            'projectId' => $projectId,
            'scenes' => $latestByScene,
            'summary' => $summary,
        ];
    }

    public function findOwnedProject(int $merchantId, int $projectId): ?array
    {
        if ($merchantId <= 0 || $projectId <= 0) {
            return null;
        }
        $project = Db::name('creative_projects')
            ->where('id', $projectId)
            ->where('merchant_id', $merchantId)
            ->where('status', '<>', 'deleted')
            ->find();
        return $project ?: null;
    }

    public function defaultDocument(string $activeView = 'design'): array
    {
        $designId = $this->uuid('design');
        $activeView = in_array($activeView, ['board', 'design', 'storyboard'], true) ? $activeView : 'design';
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'activeView' => $activeView,
            'board' => [
                'nodes' => [],
                'connections' => [],
                'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
                'cleared' => true,
            ],
            'designs' => [[
                'id' => $designId,
                'title' => '画面 1',
                'width' => 1024,
                'height' => 1024,
                'background' => '#ffffff',
                'transparent' => false,
                'canvasJson' => ['version' => '7.4.0', 'objects' => []],
                'outputAssetId' => 0,
                'updatedAt' => Web::now(),
            ]],
            'activeDesignId' => $designId,
            'storyboard' => [
                'title' => '商品视频故事板',
                'brief' => '',
                'platform' => 'douyin',
                'language' => 'zh-CN',
                'ratio' => '9:16',
                'resolution' => '720p',
                'generateAudio' => false,
                'scenes' => [],
                'activeSceneId' => '',
            ],
        ];
    }

    public function documentFromLegacyGraph(array $graph): array
    {
        $document = $this->defaultDocument('board');
        $nodeMap = [];
        $nodes = [];
        foreach (array_slice(is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [], 0, 300) as $legacyNode) {
            $assetId = (int) ($legacyNode['assetId'] ?? 0);
            if ($assetId <= 0 || isset($nodeMap[$assetId])) {
                continue;
            }
            $nodeUuid = 'asset-' . $assetId;
            $nodeMap[$assetId] = $nodeUuid;
            $nodes[] = [
                'id' => $nodeUuid,
                'type' => 'asset',
                'assetId' => $assetId,
                'x' => (float) ($legacyNode['x'] ?? 0),
                'y' => (float) ($legacyNode['y'] ?? 0),
            ];
        }
        $connections = [];
        foreach (array_slice(is_array($graph['connections'] ?? null) ? $graph['connections'] : [], 0, 600) as $legacyConnection) {
            $sourceAssetId = (int) ($legacyConnection['sourceAssetId'] ?? 0);
            $targetAssetId = (int) ($legacyConnection['targetAssetId'] ?? 0);
            if (!isset($nodeMap[$sourceAssetId], $nodeMap[$targetAssetId]) || $sourceAssetId === $targetAssetId) {
                continue;
            }
            $connections[] = [
                'id' => $this->uuid('edge'),
                'sourceNodeId' => $nodeMap[$sourceAssetId],
                'targetNodeId' => $nodeMap[$targetAssetId],
                'relation' => 'derived',
            ];
        }
        $viewport = is_array($graph['viewport'] ?? null) ? $graph['viewport'] : [];
        $document['board'] = [
            'nodes' => $nodes,
            'connections' => $connections,
            'viewport' => [
                'x' => (float) ($viewport['x'] ?? 0),
                'y' => (float) ($viewport['y'] ?? 0),
                'zoom' => (float) ($viewport['zoom'] ?? 1),
            ],
            'cleared' => $nodes === [],
        ];
        return $document;
    }

    private function withAssetNode(array $document, int $assetId): array
    {
        $board = is_array($document['board'] ?? null) ? $document['board'] : [];
        $nodes = is_array($board['nodes'] ?? null) ? array_values($board['nodes']) : [];
        foreach ($nodes as $node) {
            if ((int) ($node['assetId'] ?? 0) === $assetId) {
                $document['activeView'] = 'board';
                return [$document, (string) ($node['id'] ?? ('asset-' . $assetId)), false];
            }
        }
        $nodeUuid = $this->uuid('asset');
        $offset = count($nodes);
        $nodes[] = [
            'id' => $nodeUuid,
            'type' => 'asset',
            'assetId' => $assetId,
            'x' => 280 + ($offset % 4) * 340,
            'y' => 160 + intdiv($offset, 4) * 300,
        ];
        $board['nodes'] = $nodes;
        $board['cleared'] = false;
        $document['board'] = $board;
        $document['activeView'] = 'board';
        return [$document, $nodeUuid, true];
    }

    private function normalizeDocument(array $document, int $merchantId): array
    {
        $board = is_array($document['board'] ?? null) ? $document['board'] : $document;
        $normalizedNodes = [];
        $nodeIds = [];
        foreach (array_slice(is_array($board['nodes'] ?? null) ? array_values($board['nodes']) : [], 0, 300) as $node) {
            $type = (string) ($node['type'] ?? 'asset');
            if (!in_array($type, ['asset', 'design'], true)) {
                continue;
            }
            $id = $this->normalizeUuid((string) ($node['id'] ?? ''), $type);
            while (isset($nodeIds[$id])) {
                $id = $this->uuid($type);
            }
            $assetId = max(0, (int) ($node['assetId'] ?? 0));
            $designId = $this->normalizeOptionalUuid((string) ($node['designId'] ?? ''));
            if ($type === 'asset' && $assetId <= 0) {
                continue;
            }
            if ($type === 'design' && $designId === '') {
                continue;
            }
            $nodeIds[$id] = true;
            $normalizedNodes[] = [
                'id' => $id,
                'type' => $type,
                'assetId' => $assetId,
                'designId' => $designId,
                'x' => max(-100000, min(100000, (float) ($node['x'] ?? 0))),
                'y' => max(-100000, min(100000, (float) ($node['y'] ?? 0))),
            ];
        }

        $normalizedConnections = [];
        $connectionKeys = [];
        foreach (array_slice(is_array($board['connections'] ?? null) ? array_values($board['connections']) : [], 0, 600) as $connection) {
            $source = $this->normalizeOptionalUuid((string) ($connection['sourceNodeId'] ?? ''));
            $target = $this->normalizeOptionalUuid((string) ($connection['targetNodeId'] ?? ''));
            $key = $source . ':' . $target;
            if ($source === '' || $target === '' || $source === $target || !isset($nodeIds[$source], $nodeIds[$target]) || isset($connectionKeys[$key])) {
                continue;
            }
            $connectionKeys[$key] = true;
            $normalizedConnections[] = [
                'id' => $this->normalizeUuid((string) ($connection['id'] ?? ''), 'edge'),
                'sourceNodeId' => $source,
                'targetNodeId' => $target,
                'relation' => 'derived',
            ];
        }
        $viewport = is_array($board['viewport'] ?? null) ? $board['viewport'] : [];

        $designs = [];
        $designIds = [];
        foreach (array_slice(is_array($document['designs'] ?? null) ? array_values($document['designs']) : [], 0, 30) as $index => $design) {
            $id = $this->normalizeUuid((string) ($design['id'] ?? ''), 'design');
            while (isset($designIds[$id])) {
                $id = $this->uuid('design');
            }
            $designIds[$id] = true;
            $width = max(64, min(8192, (int) ($design['width'] ?? 1024)));
            $height = max(64, min(8192, (int) ($design['height'] ?? 1024)));
            if ($width * $height > 32_000_000) {
                $scale = sqrt(32_000_000 / ($width * $height));
                $width = max(64, (int) floor($width * $scale));
                $height = max(64, (int) floor($height * $scale));
            }
            $canvasJson = is_array($design['canvasJson'] ?? null) ? $design['canvasJson'] : ['version' => '7.4.0', 'objects' => []];
            $objects = [];
            foreach (array_slice(is_array($canvasJson['objects'] ?? null) ? array_values($canvasJson['objects']) : [], 0, 200) as $object) {
                if (!is_array($object) || !in_array((string) ($object['type'] ?? ''), ['Textbox', 'IText', 'Text', 'Rect', 'Circle', 'Image', 'FabricImage', 'Line', 'Triangle', 'Group', 'textbox', 'i-text', 'text', 'rect', 'circle', 'image', 'line', 'triangle', 'group'], true)) {
                    continue;
                }
                if (isset($object['text'])) {
                    $object['text'] = mb_strimwidth((string) $object['text'], 0, 2000, '');
                }
                unset($object['src'], $object['crossOrigin'], $object['filters'], $object['clipPath']);
                $object['assetId'] = max(0, (int) ($object['assetId'] ?? 0));
                $objects[] = $object;
            }
            $designs[] = [
                'id' => $id,
                'title' => mb_strimwidth(trim((string) ($design['title'] ?? '')), 0, 80, '') ?: '画面 ' . ($index + 1),
                'width' => $width,
                'height' => $height,
                'background' => $this->normalizeColor((string) ($design['background'] ?? '#ffffff')),
                'transparent' => (bool) ($design['transparent'] ?? false),
                'canvasJson' => [
                    'version' => mb_strimwidth((string) ($canvasJson['version'] ?? '7.4.0'), 0, 20, ''),
                    'objects' => $objects,
                ],
                'outputAssetId' => max(0, (int) ($design['outputAssetId'] ?? 0)),
                'updatedAt' => mb_strimwidth((string) ($design['updatedAt'] ?? Web::now()), 0, 40, ''),
            ];
        }
        if ($designs === []) {
            $default = $this->defaultDocument('design');
            $designs = $default['designs'];
            $designIds = [(string) $designs[0]['id'] => true];
        }
        $activeDesignId = $this->normalizeOptionalUuid((string) ($document['activeDesignId'] ?? ''));
        if ($activeDesignId === '' || !isset($designIds[$activeDesignId])) {
            $activeDesignId = (string) $designs[0]['id'];
        }

        $storyboard = $this->normalizeStoryboard($document['storyboard'] ?? []);
        $normalized = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'activeView' => in_array((string) ($document['activeView'] ?? ''), ['board', 'design', 'storyboard'], true)
                ? (string) $document['activeView']
                : 'design',
            'board' => [
                'nodes' => $normalizedNodes,
                'connections' => $normalizedConnections,
                'viewport' => [
                    'x' => max(-100000, min(100000, (float) ($viewport['x'] ?? 0))),
                    'y' => max(-100000, min(100000, (float) ($viewport['y'] ?? 0))),
                    'zoom' => max(0.25, min(2, (float) ($viewport['zoom'] ?? 1))),
                ],
                'cleared' => $normalizedNodes === [],
            ],
            'designs' => $designs,
            'activeDesignId' => $activeDesignId,
            'storyboard' => $storyboard,
        ];

        $assetIds = $this->assetIdsFromDocument($normalized);
        if ($assetIds !== []) {
            $ownedIds = array_map('intval', Db::name('assets')
                ->where('merchant_id', $merchantId)
                ->whereIn('status', ['active', 'pending'])
                ->whereIn('id', $assetIds)
                ->column('id'));
            sort($ownedIds);
            $expected = $assetIds;
            sort($expected);
            if ($ownedIds !== $expected) {
                throw new \RuntimeException('项目中包含不存在、已删除或无权访问的素材。');
            }
        }
        return $normalized;
    }

    private function syncAssetLinks(int $merchantId, int $projectId, array $document): void
    {
        foreach ($this->assetReferencesFromDocument($document) as $reference) {
            $this->attachAsset(
                $merchantId,
                $projectId,
                (int) $reference['assetId'],
                (string) $reference['nodeUuid'],
                (string) $reference['role']
            );
        }
    }

    private function assetReferencesFromDocument(array $document): array
    {
        $references = [];
        foreach ($document['board']['nodes'] ?? [] as $node) {
            $assetId = (int) ($node['assetId'] ?? 0);
            if ($assetId > 0) {
                $references[] = [
                    'assetId' => $assetId,
                    'nodeUuid' => (string) $node['id'],
                    'role' => (string) ($node['type'] ?? '') === 'design' ? 'design' : 'source',
                ];
            }
        }
        foreach ($document['designs'] ?? [] as $design) {
            $designId = (string) ($design['id'] ?? $this->uuid('design'));
            foreach ($design['canvasJson']['objects'] ?? [] as $index => $object) {
                $assetId = (int) ($object['assetId'] ?? 0);
                if ($assetId > 0) {
                    $references[] = [
                        'assetId' => $assetId,
                        'nodeUuid' => $this->normalizeUuid((string) ($object['projectNodeId'] ?? ($designId . '-object-' . $index)), 'object'),
                        'role' => 'reference',
                    ];
                }
            }
            $outputAssetId = (int) ($design['outputAssetId'] ?? 0);
            if ($outputAssetId > 0) {
                $references[] = [
                    'assetId' => $outputAssetId,
                    'nodeUuid' => $this->normalizeUuid($designId . '-output', 'output'),
                    'role' => 'output',
                ];
            }
        }
        foreach ($document['storyboard']['scenes'] ?? [] as $scene) {
            $sceneId = (string) ($scene['id'] ?? $this->uuid('scene'));
            $sourceAssetId = (int) ($scene['sourceAssetId'] ?? 0);
            if ($sourceAssetId > 0) {
                $references[] = [
                    'assetId' => $sourceAssetId,
                    'nodeUuid' => $this->normalizeUuid($sceneId . '-source', 'scene-source'),
                    'role' => 'reference',
                ];
            }
            $outputAssetId = (int) ($scene['outputAssetId'] ?? 0);
            if ($outputAssetId > 0) {
                $references[] = [
                    'assetId' => $outputAssetId,
                    'nodeUuid' => $this->normalizeUuid($sceneId . '-output', 'scene-output'),
                    'role' => 'video',
                ];
            }
        }
        return $references;
    }

    private function assetIdsFromDocument(array $document): array
    {
        $ids = [];
        foreach ($this->assetReferencesFromDocument($document) as $reference) {
            $ids[(int) $reference['assetId']] = true;
        }
        return array_keys($ids);
    }

    private function mirrorLegacyWorkspace(int $merchantId, array $document): void
    {
        $graph = $this->legacyGraphFromDocument($document);
        $json = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $now = Web::now();
        $existing = Db::name('canvas_workspaces')->where('merchant_id', $merchantId)->find();
        if ($existing) {
            Db::name('canvas_workspaces')->where('merchant_id', $merchantId)->update(['graph_json' => $json, 'updated_at' => $now]);
        } else {
            Db::name('canvas_workspaces')->insert(['merchant_id' => $merchantId, 'graph_json' => $json, 'updated_at' => $now]);
        }
    }

    private function legacyGraphFromDocument(array $document): array
    {
        $nodes = [];
        $nodeAssets = [];
        $seenAssets = [];
        foreach ($document['board']['nodes'] ?? [] as $node) {
            $assetId = (int) ($node['assetId'] ?? 0);
            if ($assetId <= 0 || isset($seenAssets[$assetId])) {
                continue;
            }
            $seenAssets[$assetId] = true;
            $nodeAssets[(string) ($node['id'] ?? '')] = $assetId;
            $nodes[] = [
                'assetId' => $assetId,
                'x' => (float) ($node['x'] ?? 0),
                'y' => (float) ($node['y'] ?? 0),
            ];
        }
        $connections = [];
        foreach ($document['board']['connections'] ?? [] as $connection) {
            $source = $nodeAssets[(string) ($connection['sourceNodeId'] ?? '')] ?? 0;
            $target = $nodeAssets[(string) ($connection['targetNodeId'] ?? '')] ?? 0;
            if ($source > 0 && $target > 0 && $source !== $target) {
                $connections[] = ['sourceAssetId' => $source, 'targetAssetId' => $target];
            }
        }
        return [
            'nodes' => $nodes,
            'connections' => $connections,
            'viewport' => $document['board']['viewport'] ?? ['x' => 0, 'y' => 0, 'zoom' => 1],
            'cleared' => $nodes === [],
        ];
    }

    private function pruneUnownedAssetReferences(array $document, int $merchantId): array
    {
        $assetIds = $this->assetIdsFromDocument($document);
        if ($assetIds === []) {
            return $document;
        }
        $ownedIds = array_map('intval', Db::name('assets')
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['active', 'pending'])
            ->whereIn('id', $assetIds)
            ->column('id'));
        $owned = array_fill_keys($ownedIds, true);

        $validNodes = [];
        foreach ($document['board']['nodes'] ?? [] as $node) {
            $assetId = (int) ($node['assetId'] ?? 0);
            if ($assetId <= 0 || isset($owned[$assetId])) {
                $validNodes[] = $node;
            }
        }
        $validNodeIds = [];
        foreach ($validNodes as $node) {
            $validNodeIds[(string) ($node['id'] ?? '')] = true;
        }
        $validConnections = [];
        foreach ($document['board']['connections'] ?? [] as $connection) {
            $source = (string) ($connection['sourceNodeId'] ?? '');
            $target = (string) ($connection['targetNodeId'] ?? '');
            if (isset($validNodeIds[$source], $validNodeIds[$target])) {
                $validConnections[] = $connection;
            }
        }
        $document['board']['nodes'] = $validNodes;
        $document['board']['connections'] = $validConnections;
        $document['board']['cleared'] = $validNodes === [];

        foreach ($document['designs'] ?? [] as $index => $design) {
            $objects = [];
            foreach ($design['canvasJson']['objects'] ?? [] as $object) {
                $assetId = (int) ($object['assetId'] ?? 0);
                if ($assetId <= 0 || isset($owned[$assetId])) {
                    $objects[] = $object;
                }
            }
            $document['designs'][$index]['canvasJson']['objects'] = $objects;
            $outputAssetId = (int) ($design['outputAssetId'] ?? 0);
            if ($outputAssetId > 0 && !isset($owned[$outputAssetId])) {
                $document['designs'][$index]['outputAssetId'] = 0;
            }
        }
        foreach ($document['storyboard']['scenes'] ?? [] as $index => $scene) {
            $sourceAssetId = (int) ($scene['sourceAssetId'] ?? 0);
            if ($sourceAssetId > 0 && !isset($owned[$sourceAssetId])) {
                $document['storyboard']['scenes'][$index]['sourceAssetId'] = 0;
                $document['storyboard']['scenes'][$index]['sourceType'] = 'none';
            }
            $outputAssetId = (int) ($scene['outputAssetId'] ?? 0);
            if ($outputAssetId > 0 && !isset($owned[$outputAssetId])) {
                $document['storyboard']['scenes'][$index]['outputAssetId'] = 0;
            }
        }
        return $document;
    }

    private function normalizeStoryboard(mixed $value): array
    {
        $storyboard = is_array($value) ? $value : [];
        $sceneIds = [];
        $scenes = [];
        foreach (array_slice(is_array($storyboard['scenes'] ?? null) ? array_values($storyboard['scenes']) : [], 0, 20) as $index => $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $id = $this->normalizeUuid((string) ($scene['id'] ?? ''), 'scene');
            while (isset($sceneIds[$id])) {
                $id = $this->uuid('scene');
            }
            $sceneIds[$id] = true;
            $motion = (string) ($scene['motionPreset'] ?? 'product_showcase');
            if (!in_array($motion, ['product_showcase', 'slow_push', 'orbit', 'lifestyle'], true)) {
                $motion = 'product_showcase';
            }
            $duration = (int) ($scene['durationSeconds'] ?? 5);
            if (!in_array($duration, [5, 10], true)) {
                $duration = 5;
            }
            $transition = (string) ($scene['transition'] ?? 'cut');
            if (!in_array($transition, ['cut', 'fade', 'dissolve', 'none'], true)) {
                $transition = 'cut';
            }
            $sourceType = (string) ($scene['sourceType'] ?? 'asset');
            if (!in_array($sourceType, ['asset', 'previous_tail', 'none'], true)) {
                $sourceType = 'asset';
            }
            $status = (string) ($scene['status'] ?? 'draft');
            if (!in_array($status, ['draft', 'queued', 'pending_gateway', 'running', 'manual_review', 'completed', 'partial', 'failed', 'result_missing', 'cancelled'], true)) {
                $status = 'draft';
            }
            $scenes[] = [
                'id' => $id,
                'title' => mb_strimwidth(trim((string) ($scene['title'] ?? '')), 0, 80, '') ?: '镜头 ' . ($index + 1),
                'prompt' => mb_strimwidth(trim((string) ($scene['prompt'] ?? '')), 0, 1200, ''),
                'motionPreset' => $motion,
                'durationSeconds' => $duration,
                'subtitle' => mb_strimwidth(trim((string) ($scene['subtitle'] ?? '')), 0, 200, ''),
                'transition' => $transition,
                'sourceType' => $sourceType,
                'sourceAssetId' => max(0, (int) ($scene['sourceAssetId'] ?? 0)),
                'previousSceneId' => $this->normalizeOptionalUuid((string) ($scene['previousSceneId'] ?? '')),
                'jobId' => max(0, (int) ($scene['jobId'] ?? 0)),
                'outputAssetId' => max(0, (int) ($scene['outputAssetId'] ?? 0)),
                'status' => $status,
                'createdAt' => mb_strimwidth((string) ($scene['createdAt'] ?? Web::now()), 0, 40, ''),
                'updatedAt' => mb_strimwidth((string) ($scene['updatedAt'] ?? Web::now()), 0, 40, ''),
            ];
        }
        foreach ($scenes as $index => $scene) {
            $previousSceneId = (string) $scene['previousSceneId'];
            if ($previousSceneId === '' || $previousSceneId === (string) $scene['id'] || !isset($sceneIds[$previousSceneId])) {
                $scenes[$index]['previousSceneId'] = '';
                if ((string) $scene['sourceType'] === 'previous_tail') {
                    $scenes[$index]['sourceType'] = 'none';
                }
            }
        }
        $activeSceneId = $this->normalizeOptionalUuid((string) ($storyboard['activeSceneId'] ?? ''));
        if ($activeSceneId === '' || !isset($sceneIds[$activeSceneId])) {
            $activeSceneId = (string) ($scenes[0]['id'] ?? '');
        }
        $platform = (string) ($storyboard['platform'] ?? 'douyin');
        if (!array_key_exists($platform, generation_platform_options())) {
            $platform = 'douyin';
        }
        $language = (string) ($storyboard['language'] ?? 'zh-CN');
        if (!array_key_exists($language, generation_language_options())) {
            $language = 'zh-CN';
        }
        $ratio = (string) ($storyboard['ratio'] ?? '9:16');
        if (!in_array($ratio, ['1:1', '16:9', '9:16', '4:3', '3:4'], true)) {
            $ratio = '9:16';
        }
        $resolution = (string) ($storyboard['resolution'] ?? '720p');
        if (!in_array($resolution, ['720p', '1080p'], true)) {
            $resolution = '720p';
        }
        return [
            'title' => mb_strimwidth(trim((string) ($storyboard['title'] ?? '')), 0, 100, '') ?: '商品视频故事板',
            'brief' => mb_strimwidth(trim((string) ($storyboard['brief'] ?? '')), 0, 1200, ''),
            'platform' => $platform,
            'language' => $language,
            'ratio' => $ratio,
            'resolution' => $resolution,
            'generateAudio' => (bool) ($storyboard['generateAudio'] ?? false),
            'scenes' => $scenes,
            'activeSceneId' => $activeSceneId,
        ];
    }

    private function projectTypeForDocument(array $document): string
    {
        $hasStoryboard = !empty($document['storyboard']['scenes']);
        if (!$hasStoryboard) {
            return 'image';
        }
        $hasImageWork = !empty($document['board']['nodes']);
        foreach ($document['designs'] ?? [] as $design) {
            if (!empty($design['canvasJson']['objects']) || (int) ($design['outputAssetId'] ?? 0) > 0) {
                $hasImageWork = true;
                break;
            }
        }
        return $hasImageWork ? 'mixed' : 'video';
    }

    private function pruneRevisions(int $projectId): void
    {
        $ids = array_map('intval', Db::name('creative_project_revisions')
            ->where('project_id', $projectId)
            ->order('revision_no', 'desc')
            ->column('id'));
        $staleIds = array_slice($ids, self::MAX_REVISIONS);
        if ($staleIds !== []) {
            Db::name('creative_project_revisions')->whereIn('id', $staleIds)->delete();
        }
    }

    private function decodeDocument(string $json): array
    {
        $document = json_decode($json, true);
        return is_array($document) ? $document : $this->defaultDocument('design');
    }

    private function encodeDocument(array $document): string
    {
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > self::MAX_DOCUMENT_BYTES) {
            throw new \RuntimeException('项目内容无法保存或超过 2MB 上限。');
        }
        return $json;
    }

    private function normalizeColor(string $color): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : '#ffffff';
    }

    private function normalizeOptionalUuid(string $uuid): string
    {
        $uuid = trim($uuid);
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{5,63}$/', $uuid) ? $uuid : '';
    }

    private function normalizeUuid(string $uuid, string $prefix): string
    {
        return $this->normalizeOptionalUuid($uuid) ?: $this->uuid($prefix);
    }

    private function uuid(string $prefix): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '', $prefix) . '-' . bin2hex(random_bytes(8));
    }
}

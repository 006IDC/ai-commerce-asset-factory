<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use app\support\Web;
use think\facade\Db;

final class MarketResearchService
{
    private const ENDPOINT = 'https://api.tavily.com/search';

    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function configuration(): array
    {
        $key = trim($this->settings->get('tavily_api_key'));
        return [
            'enabled' => $this->settings->get('tavily_search_enabled') === '1',
            'configured' => $key !== '',
            'available' => $key !== '' && $this->settings->get('tavily_search_enabled') === '1',
            'keyConfigured' => $key !== '',
            'keyStoredInDatabase' => $this->settings->hasDatabaseValue('tavily_api_key'),
            'mode' => $this->mode(),
            'maxResults' => $this->boundedInteger('tavily_max_results', 5, 3, 10),
            'cacheHours' => $this->boundedInteger('tavily_cache_hours', 168, 1, 2160),
            'businessCredits' => $this->businessCredits(),
        ];
    }

    public function businessCredits(): int
    {
        return $this->boundedInteger('tavily_business_credits', 2, 0, 1000000);
    }

    public function research(int $merchantId, ?int $skuId, array $product, string $platform): array
    {
        $configuration = $this->configuration();
        if (!$configuration['available']) {
            throw new RuntimeException('联网市场洞察当前未启用或尚未配置 API Key。');
        }

        $name = mb_strimwidth(trim((string) ($product['name'] ?? '')), 0, 160, '');
        $category = mb_strimwidth(trim((string) ($product['category'] ?? '')), 0, 100, '');
        $brief = mb_strimwidth(trim((string) ($product['brief'] ?? '')), 0, 300, '');
        $keyword = mb_strimwidth(trim((string) ($product['query'] ?? '')), 0, 180, '');
        $subject = trim(implode(' ', array_filter([$keyword, $name, $category])));
        if (mb_strlen($subject) < 2) {
            throw new RuntimeException('启用联网市场洞察时，请填写商品名称或搜索关键词。');
        }

        $queries = [$subject . ' 消费者关注点 使用场景 购买决策 电商'];
        if ($configuration['mode'] === 'deep') {
            $queries[] = $subject . ' 市场趋势 常见痛点 竞品卖点 ' . platform_label($platform);
        }
        if ($brief !== '') {
            $queries[0] .= ' ' . $brief;
        }
        $queryHash = hash('sha256', json_encode([
            'merchant' => $merchantId,
            'queries' => $queries,
            'mode' => $configuration['mode'],
            'max' => $configuration['maxResults'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $cached = Db::name('market_research_cache')
            ->where('merchant_id', $merchantId)
            ->where('query_hash', $queryHash)
            ->where('expires_at', '>', Web::now())
            ->find();
        if ($cached) {
            $result = json_decode((string) $cached['result_json'], true);
            if (is_array($result)) {
                $result['cached'] = true;
                $result['cacheId'] = (int) $cached['id'];
                return $result;
            }
        }

        $sources = [];
        $providerCredits = 0;
        foreach ($queries as $query) {
            $response = $this->search($query, (int) $configuration['maxResults']);
            $providerCredits += 2;
            foreach ($response as $item) {
                $url = trim((string) ($item['url'] ?? ''));
                if ($url === '' || isset($sources[$url])) {
                    continue;
                }
                $sources[$url] = [
                    'id' => substr(hash('sha256', $url), 0, 16),
                    'title' => mb_strimwidth(trim((string) ($item['title'] ?? '外部来源')), 0, 240, ''),
                    'url' => $url,
                    'content' => mb_strimwidth(trim((string) ($item['content'] ?? '')), 0, 1200, ''),
                    'score' => isset($item['score']) ? round((float) $item['score'], 4) : null,
                    'selected' => true,
                ];
                if (count($sources) >= (int) $configuration['maxResults']) {
                    break 2;
                }
            }
        }
        if ($sources === []) {
            throw new RuntimeException('联网搜索没有找到可用市场资料，请调整商品关键词后重试。');
        }

        $now = Web::now();
        $result = [
            'query' => $subject,
            'queries' => $queries,
            'sources' => array_values($sources),
            'provider' => 'tavily',
            'providerCredits' => $providerCredits,
            'cached' => false,
            'researchedAt' => $now,
        ];
        $expiresAt = date('Y-m-d H:i:s', time() + ((int) $configuration['cacheHours'] * 3600));
        $payload = [
            'sku_id' => $skuId,
            'query_text' => $subject,
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_count' => count($sources),
            'provider_credits' => $providerCredits,
            'expires_at' => $expiresAt,
            'updated_at' => $now,
        ];
        $existing = Db::name('market_research_cache')->where('merchant_id', $merchantId)->where('query_hash', $queryHash)->find();
        if ($existing) {
            Db::name('market_research_cache')->where('id', $existing['id'])->update($payload);
            $cacheId = (int) $existing['id'];
        } else {
            $cacheId = (int) Db::name('market_research_cache')->insertGetId($payload + [
                'merchant_id' => $merchantId,
                'query_hash' => $queryHash,
                'created_at' => $now,
            ]);
        }
        $result['cacheId'] = $cacheId;
        return $result;
    }

    public function promptContext(array $research): array
    {
        $sources = [];
        foreach (array_slice((array) ($research['sources'] ?? []), 0, 10) as $index => $source) {
            if (!is_array($source)) {
                continue;
            }
            if (($source['selected'] ?? true) === false) {
                continue;
            }
            $sources[] = [
                '序号' => $index + 1,
                '标题' => (string) ($source['title'] ?? ''),
                '摘要' => (string) ($source['content'] ?? ''),
                '链接' => (string) ($source['url'] ?? ''),
            ];
        }
        return [
            '检索关键词' => (string) ($research['query'] ?? ''),
            '外部资料' => $sources,
            '使用规则' => '外部资料只用于归纳常见场景、关注点和表达方向，不得当作本商品已确认参数、功效、销量、认证或品牌事实；与商品图或商户资料冲突时，以商品图和商户确认信息为准。',
        ];
    }

    public function selectSources(array $research, array $selectedIds): array
    {
        $selectedIds = array_fill_keys(array_map('strval', $selectedIds), true);
        foreach ((array) ($research['sources'] ?? []) as $index => $source) {
            if (!is_array($source)) {
                continue;
            }
            $id = (string) ($source['id'] ?? substr(hash('sha256', (string) ($source['url'] ?? '')), 0, 16));
            $source['id'] = $id;
            $source['selected'] = isset($selectedIds[$id]);
            $research['sources'][$index] = $source;
        }
        $research['selectedCount'] = count(array_filter((array) ($research['sources'] ?? []), static fn (mixed $source): bool => is_array($source) && ($source['selected'] ?? true) !== false));

        return $research;
    }

    public function testConnection(): array
    {
        if (trim($this->settings->get('tavily_api_key')) === '') {
            return ['ok' => false, 'message' => '请先保存 Tavily API Key。'];
        }
        try {
            $results = $this->search('AI ecommerce product research', 1, 'basic');
            return ['ok' => $results !== [], 'message' => $results !== [] ? 'Tavily 连接正常，已返回测试结果。' : 'Tavily 已连接，但测试查询没有返回结果。'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    private function search(string $query, int $maxResults, string $depth = 'advanced'): array
    {
        $curl = curl_init(self::ENDPOINT);
        if ($curl === false) {
            throw new RuntimeException('无法初始化 Tavily 请求。');
        }
        $payload = json_encode([
            'query' => $query,
            'search_depth' => $depth,
            'max_results' => max(1, min(10, $maxResults)),
            'include_answer' => false,
            'include_images' => false,
            'include_raw_content' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . trim($this->settings->get('tavily_api_key')),
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false || $error !== '') {
            throw new RuntimeException('Tavily 请求失败：' . ($error ?: '网络连接异常'));
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['detail']['error'] ?? $decoded['detail'] ?? $decoded['message'] ?? '') : '';
            throw new RuntimeException('Tavily 返回错误（HTTP ' . $status . '）' . ($message !== '' ? '：' . $message : '。'));
        }
        return is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
    }

    private function mode(): string
    {
        return $this->settings->get('tavily_search_mode') === 'deep' ? 'deep' : 'standard';
    }

    private function boundedInteger(string $key, int $fallback, int $minimum, int $maximum): int
    {
        $value = $this->settings->get($key);
        if (!preg_match('/^\d+$/', $value)) {
            return $fallback;
        }
        return max($minimum, min($maximum, (int) $value));
    }
}

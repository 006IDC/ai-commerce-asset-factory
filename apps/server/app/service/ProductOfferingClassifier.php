<?php

declare(strict_types=1);

namespace app\service;

final class ProductOfferingClassifier
{
    public function classify(array $product): string
    {
        $values = [
            $product['product_name'] ?? $product['name'] ?? '',
            $product['category'] ?? '',
            $product['product_description'] ?? '',
            $product['creative_brief'] ?? '',
            $product['materials'] ?? '',
            ...decode_json_list($product['selling_points'] ?? '[]'),
            ...decode_json_list($product['core_functions'] ?? '[]'),
            ...decode_json_list($product['use_scenarios'] ?? '[]'),
        ];
        $text = implode(' ', array_map(static function (mixed $value): string {
            return is_scalar($value) ? trim((string) $value) : '';
        }, $values));
        if ($text === '') {
            return 'physical';
        }

        $servicePattern = '/租赁|租用|出租|续费|订阅|按月(?:付费|计费|租)|按年(?:付费|计费|租)|'
            . '云服务器|云主机|裸金属|物理机(?:租赁|租用|托管)|服务器(?:租赁|租用|托管)|'
            . '\bVPS\b|\bSaaS\b|\bPaaS\b|\bIaaS\b|\bAPI\b|域名(?:注册|租赁|续费)|DNS(?:解析|服务)|'
            . '带宽服务|主机托管|机柜托管|运维服务|软件服务|技术服务|代运营|咨询服务|培训服务|解决方案/iu';

        return preg_match($servicePattern, $text) === 1 ? 'service' : 'physical';
    }

    public function isService(array $product): bool
    {
        return $this->classify($product) === 'service';
    }
}

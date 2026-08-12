<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class OperationsSettings
{
    public function __construct(private readonly SystemSettings $settings)
    {
    }

    public function registrationBenefit(): array
    {
        $credits = $this->boundedInteger($this->settings->get('registration_gift_credits'), 20, 0, 1000000);
        $planId = $this->boundedInteger($this->settings->get('registration_gift_plan_id'), 0, 0, PHP_INT_MAX);
        $plan = $planId > 0 ? Db::name('subscription_plans')->where('id', $planId)->find() : null;

        return [
            'credits' => $credits,
            'planId' => $planId,
            'plan' => is_array($plan) ? $plan : null,
        ];
    }

    public function announcement(): array
    {
        $contactUrl = trim($this->settings->get('merchant_announcement_contact_url'));
        if (!$this->validContactUrl($contactUrl)) {
            $contactUrl = '';
        }
        return [
            'enabled' => $this->settings->get('merchant_announcement_enabled') === '1',
            'title' => trim($this->settings->get('merchant_announcement_title')),
            'content' => trim($this->settings->get('merchant_announcement_content')),
            'contactLabel' => trim($this->settings->get('merchant_announcement_contact_label')),
            'contactUrl' => $contactUrl,
        ];
    }

    private function validContactUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https', 'mailto', 'tel'], true);
    }

    private function boundedInteger(string $value, int $fallback, int $minimum, int $maximum): int
    {
        if (!preg_match('/^\d+$/', $value)) {
            return $fallback;
        }

        return max($minimum, min($maximum, (int) $value));
    }
}

<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use Throwable;
use app\support\Web;
use think\facade\Db;

final class SystemSettings
{
    private const DEFINITIONS = [
        'app_public_url' => ['env' => 'APP_PUBLIC_URL', 'default' => '', 'secret' => false],
        'default_image_model' => ['env' => 'DEFAULT_IMAGE_MODEL', 'default' => 'gpt-image-2', 'secret' => false],
        'default_image_edit_mode' => ['env' => 'DEFAULT_IMAGE_EDIT_MODE', 'default' => 'auto', 'secret' => false],
        'deepseek_text_model' => ['env' => 'DEEPSEEK_TEXT_MODEL', 'default' => 'deepseek-v4-flash', 'secret' => false],
        'zhipu_vision_model' => ['env' => 'ZHIPU_VISION_MODEL', 'default' => 'glm-4.6v', 'secret' => false],
        'default_vision_model' => ['env' => 'DEFAULT_VISION_MODEL', 'default' => 'glm-4.6v', 'secret' => false],
        'ai_routing_mode' => ['env' => 'AI_ROUTING_MODE', 'default' => 'capability', 'secret' => false],
        'ai_execution_mode' => ['env' => 'AI_EXECUTION_MODE', 'default' => 'native', 'secret' => false],
        'ai_native_capabilities' => ['env' => 'AI_NATIVE_CAPABILITIES', 'default' => '["safety_text","safety_vision","product_vision","content_planner"]', 'secret' => false],
        'ai_routing_gray_merchant_ids' => ['env' => 'AI_ROUTING_GRAY_MERCHANT_IDS', 'default' => '[]', 'secret' => false],
        'sub2api_enabled' => ['env' => 'SUB2API_ENABLED', 'default' => '0', 'secret' => false],
        'sub2api_base_url' => ['env' => 'SUB2API_BASE_URL', 'default' => 'http://127.0.0.1:3002', 'secret' => false],
        'sub2api_service_key' => ['env' => 'SUB2API_SERVICE_KEY', 'default' => '', 'secret' => true],
        'sub2api_timeout_seconds' => ['env' => 'SUB2API_TIMEOUT_SECONDS', 'default' => '450', 'secret' => false],
        'sub2api_image_async_enabled' => ['env' => 'SUB2API_IMAGE_ASYNC_ENABLED', 'default' => '0', 'secret' => false],
        'sub2api_gray_merchant_ids' => ['env' => 'SUB2API_GRAY_MERCHANT_IDS', 'default' => '[]', 'secret' => false],
        'image_generation_credits' => ['env' => 'IMAGE_GENERATION_CREDITS', 'default' => '8', 'secret' => false],
        'video_generation_credits_per_second' => ['env' => 'VIDEO_GENERATION_CREDITS_PER_SECOND', 'default' => '6', 'secret' => false],
        'video_worker_poll_interval_seconds' => ['env' => 'VIDEO_WORKER_POLL_INTERVAL_SECONDS', 'default' => '8', 'secret' => false],
        'video_worker_max_runtime_seconds' => ['env' => 'VIDEO_WORKER_MAX_RUNTIME_SECONDS', 'default' => '3600', 'secret' => false],
        'comfyui_enabled' => ['env' => 'COMFYUI_ENABLED', 'default' => '0', 'secret' => false],
        'comfyui_api_mode' => ['env' => 'COMFYUI_API_MODE', 'default' => 'local', 'secret' => false],
        'comfyui_base_url' => ['env' => 'COMFYUI_BASE_URL', 'default' => '', 'secret' => false],
        'comfyui_base_urls' => ['env' => 'COMFYUI_BASE_URLS', 'default' => '[]', 'secret' => false],
        'comfyui_api_key' => ['env' => 'COMFYUI_API_KEY', 'default' => '', 'secret' => true],
        'comfyui_request_timeout_seconds' => ['env' => 'COMFYUI_REQUEST_TIMEOUT_SECONDS', 'default' => '30', 'secret' => false],
        'comfyui_workflow_timeout_seconds' => ['env' => 'COMFYUI_WORKFLOW_TIMEOUT_SECONDS', 'default' => '600', 'secret' => false],
        'canvas_edit_credits' => ['env' => 'CANVAS_EDIT_CREDITS', 'default' => '', 'secret' => false],
        'product_recognition_credits' => ['env' => 'PRODUCT_RECOGNITION_CREDITS', 'default' => '0', 'secret' => false],
        'prompt_optimization_credits' => ['env' => 'PROMPT_OPTIMIZATION_CREDITS', 'default' => '0', 'secret' => false],
        'tavily_search_enabled' => ['env' => 'TAVILY_SEARCH_ENABLED', 'default' => '0', 'secret' => false],
        'tavily_api_key' => ['env' => 'TAVILY_API_KEY', 'default' => '', 'secret' => true],
        'tavily_search_mode' => ['env' => 'TAVILY_SEARCH_MODE', 'default' => 'standard', 'secret' => false],
        'tavily_max_results' => ['env' => 'TAVILY_MAX_RESULTS', 'default' => '5', 'secret' => false],
        'tavily_cache_hours' => ['env' => 'TAVILY_CACHE_HOURS', 'default' => '168', 'secret' => false],
        'tavily_business_credits' => ['env' => 'TAVILY_BUSINESS_CREDITS', 'default' => '2', 'secret' => false],
        'worker_global_concurrency' => ['env' => 'WORKER_GLOBAL_CONCURRENCY', 'default' => '10', 'secret' => false],
        'worker_vision_concurrency' => ['env' => 'WORKER_VISION_CONCURRENCY', 'default' => '3', 'secret' => false],
        'worker_merchant_concurrency' => ['env' => 'WORKER_MERCHANT_CONCURRENCY', 'default' => '3', 'secret' => false],
        'worker_job_concurrency' => ['env' => 'WORKER_JOB_CONCURRENCY', 'default' => '3', 'secret' => false],
        'registration_gift_credits' => ['env' => 'REGISTRATION_GIFT_CREDITS', 'default' => '20', 'secret' => false],
        'registration_gift_plan_id' => ['env' => 'REGISTRATION_GIFT_PLAN_ID', 'default' => '', 'secret' => false],
        'merchant_announcement_enabled' => ['env' => 'MERCHANT_ANNOUNCEMENT_ENABLED', 'default' => '0', 'secret' => false],
        'merchant_announcement_title' => ['env' => 'MERCHANT_ANNOUNCEMENT_TITLE', 'default' => '套餐与额度服务', 'secret' => false],
        'merchant_announcement_content' => ['env' => 'MERCHANT_ANNOUNCEMENT_CONTENT', 'default' => '如需开通使用套餐或购买额度卡密，请联系平台运营人员。', 'secret' => false],
        'merchant_announcement_contact_label' => ['env' => 'MERCHANT_ANNOUNCEMENT_CONTACT_LABEL', 'default' => '联系运营', 'secret' => false],
        'merchant_announcement_contact_url' => ['env' => 'MERCHANT_ANNOUNCEMENT_CONTACT_URL', 'default' => '', 'secret' => false],
        'geetest_captcha_id' => ['env' => 'GEETEST_CAPTCHA_ID', 'default' => '', 'secret' => false],
        'geetest_private_key' => ['env' => 'GEETEST_PRIVATE_KEY', 'default' => '', 'secret' => true],
        'content_safety_enabled' => ['env' => 'CONTENT_SAFETY_ENABLED', 'default' => '1', 'secret' => false],
        'content_safety_external_enabled' => ['env' => 'CONTENT_SAFETY_EXTERNAL_ENABLED', 'default' => '1', 'secret' => false],
        'content_safety_fail_closed' => ['env' => 'CONTENT_SAFETY_FAIL_CLOSED', 'default' => '0', 'secret' => false],
        'content_safety_model' => ['env' => 'CONTENT_SAFETY_MODEL', 'default' => 'deepseek-v4-flash', 'secret' => false],
        'content_safety_fallback_model' => ['env' => 'CONTENT_SAFETY_FALLBACK_MODEL', 'default' => 'deepseek-v4-pro', 'secret' => false],
        'content_safety_penalty_credits' => ['env' => 'CONTENT_SAFETY_PENALTY_CREDITS', 'default' => '5', 'secret' => false],
        'content_safety_terms' => ['env' => 'CONTENT_SAFETY_TERMS', 'default' => "儿童色情\n未成年色情\n强奸\n约炮\n裸照\n毒品交易\n冰毒\n海洛因\n枪支出售\n买凶\n恐怖袭击\n血腥肢解\n虐杀\n伪造证件\n洗钱教程", 'secret' => false],
        'storage_driver' => ['env' => 'STORAGE_DRIVER', 'default' => 'local', 'secret' => false],
        'storage_endpoint' => ['env' => 'STORAGE_ENDPOINT', 'default' => '', 'secret' => false],
        'storage_region' => ['env' => 'STORAGE_REGION', 'default' => '', 'secret' => false],
        'storage_bucket' => ['env' => 'STORAGE_BUCKET', 'default' => '', 'secret' => false],
        'storage_access_key' => ['env' => 'STORAGE_ACCESS_KEY', 'default' => '', 'secret' => true],
        'storage_secret_key' => ['env' => 'STORAGE_SECRET_KEY', 'default' => '', 'secret' => true],
        'storage_public_url' => ['env' => 'STORAGE_PUBLIC_URL', 'default' => '', 'secret' => false],
        'storage_path_prefix' => ['env' => 'STORAGE_PATH_PREFIX', 'default' => 'generated', 'secret' => false],
        'storage_local_backup_enabled' => ['env' => 'STORAGE_LOCAL_BACKUP_ENABLED', 'default' => '1', 'secret' => false],
        'smtp_host' => ['env' => 'SMTP_HOST', 'default' => '', 'secret' => false],
        'smtp_port' => ['env' => 'SMTP_PORT', 'default' => '465', 'secret' => false],
        'smtp_encryption' => ['env' => 'SMTP_ENCRYPTION', 'default' => 'ssl', 'secret' => false],
        'smtp_username' => ['env' => 'SMTP_USERNAME', 'default' => '', 'secret' => false],
        'smtp_password' => ['env' => 'SMTP_PASSWORD', 'default' => '', 'secret' => true],
        'smtp_from_email' => ['env' => 'SMTP_FROM_EMAIL', 'default' => '', 'secret' => false],
        'smtp_from_name' => ['env' => 'SMTP_FROM_NAME', 'default' => 'AI 电商素材工厂', 'secret' => false],
    ];

    public function __construct(private readonly SecretCipher $cipher)
    {
    }

    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public function get(string $key): string
    {
        $definition = self::DEFINITIONS[$key] ?? null;
        if ($definition === null) {
            throw new RuntimeException('未知系统设置：' . $key);
        }

        $row = $this->databaseRow($key);
        if ($row !== null) {
            $value = (string) $row['setting_value'];
            return (bool) $row['is_secret'] ? $this->cipher->decrypt($value) : $value;
        }

        return (string) env($definition['env'], $definition['default']);
    }

    public function setMany(array $values, int $adminId): array
    {
        $changed = [];
        Db::transaction(function () use ($values, $adminId, &$changed): void {
            foreach ($values as $key => $value) {
                $definition = self::DEFINITIONS[$key] ?? null;
                if ($definition === null) {
                    throw new RuntimeException('未知系统设置：' . $key);
                }

                $plainValue = (string) $value;
                $storedValue = $definition['secret'] ? $this->cipher->encrypt($plainValue) : $plainValue;
                $existing = Db::name('system_settings')->where('setting_key', $key)->find();
                $now = Web::now();
                $payload = [
                    'setting_value' => $storedValue,
                    'is_secret' => $definition['secret'] ? 1 : 0,
                    'updated_by' => $adminId,
                    'updated_at' => $now,
                ];
                if ($existing) {
                    Db::name('system_settings')->where('setting_key', $key)->update($payload);
                } else {
                    Db::execute(
                        'INSERT INTO system_settings (setting_key,setting_value,is_secret,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,?)',
                        [$key, $storedValue, $definition['secret'] ? 1 : 0, $adminId, $now, $now]
                    );
                }
                $changed[] = $key;
            }
        });

        return $changed;
    }

    public function hasDatabaseValue(string $key): bool
    {
        return $this->databaseRow($key) !== null;
    }

    private function databaseRow(string $key): ?array
    {
        try {
            $row = Db::name('system_settings')->where('setting_key', $key)->find();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

}

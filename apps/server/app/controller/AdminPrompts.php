<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\AdminPromptManagementService;
use app\service\PromptTemplateService;
use app\support\Audit;
use app\support\Web;
use think\Response;
use think\facade\Db;
use think\facade\Session;
use Throwable;

final class AdminPrompts extends BaseController
{
    public function index(PromptTemplateService $prompts, AdminPromptManagementService $management): Response
    {
        $admin = $this->admin();
        $prompts->ensureBuiltIns();
        $productCode = trim((string) $this->request->get('product', ''));
        if ($productCode !== '') {
            try {
                $business = $management->business($productCode);
            } catch (Throwable $exception) {
                return Web::redirect('/admin/prompts', $exception->getMessage(), 'error');
            }
            return $this->render('admin/prompt_business', compact('admin', 'business') + [
                'pageTitle' => '提示词管理 · ' . (string) $business['product']['display_name'],
            ]);
        }

        $promptGroup = trim((string) $this->request->get('group', 'core')) === 'inspiration' ? 'inspiration' : 'core';
        $data = $management->dashboard();
        $inspirationTemplates = $promptGroup === 'inspiration' ? $management->inspirationTemplates() : [];
        $inspirationCategoryLabels = $prompts->freeCategories();
        return $this->render('admin/prompt_manager', compact(
            'admin', 'promptGroup', 'data', 'inspirationTemplates', 'inspirationCategoryLabels'
        ) + ['pageTitle' => '提示词管理']);
    }

    public function coreEdit(string $code, int $id, AdminPromptManagementService $management): Response
    {
        $admin = $this->admin();
        try {
            $prompt = $management->corePrompt($code, $id);
        } catch (Throwable $exception) {
            return Web::redirect('/admin/prompts', $exception->getMessage(), 'error');
        }
        return $this->render('admin/prompt_core_form', compact('admin', 'prompt') + [
            'pageTitle' => '编辑提示词 · ' . (string) $prompt['name'],
        ]);
    }

    public function saveCore(string $code, int $id, AdminPromptManagementService $management): Response
    {
        $admin = $this->admin();
        try {
            $prompt = $management->corePrompt($code, $id);
            $content = trim((string) $this->request->post('content', ''));
            if (mb_strlen($content) < 20 || mb_strlen($content) > 20000) {
                throw new \RuntimeException('提示词模板应为 20 到 20000 个字符。');
            }
            Db::name('prompt_templates')->where('id', $id)->update([
                'content' => $content,
                'status' => 'active',
                'is_default' => 1,
                'is_customized' => (int) ($prompt['is_system'] ?? 0) === 1 ? 1 : (int) ($prompt['is_customized'] ?? 0),
                'updated_by_admin_id' => (int) $admin['id'],
                'updated_at' => Web::now(),
            ]);
            Audit::log('admin', (int) $admin['id'], 'prompt_template.update', 'prompt_template', $id, $this->request->ip(), [
                'product_code' => $code,
                'content_hash' => hash('sha256', $content),
            ]);
            return Web::redirect('/admin/prompts?product=' . rawurlencode($code), '提示词模板已保存，后续新任务立即读取。');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/prompts/core/' . rawurlencode($code) . '/' . $id . '/edit', $exception->getMessage(), 'error');
        }
    }

    public function createPage(PromptTemplateService $prompts): Response
    {
        $admin = $this->admin();
        $template = [
            'name' => '', 'scene' => PromptTemplateService::SCENE_FREE, 'platform' => '*',
            'asset_type' => 'free_product', 'content' => '', 'status' => 'inactive',
            'is_default' => 0, 'is_system' => 0,
        ];
        return $this->render('admin/prompt_form', compact('admin', 'template', 'prompts') + [
            'formAction' => '/admin/prompts', 'isEdit' => false, 'pageTitle' => '新建灵感模板',
        ]);
    }

    public function create(PromptTemplateService $prompts): Response
    {
        $admin = $this->admin();
        try {
            $data = $this->validatedFreeData($prompts);
            $now = Web::now();
            $id = (int) Db::name('prompt_templates')->insertGetId($data + [
                'code' => 'custom.free.' . date('YmdHis') . '.' . bin2hex(random_bytes(3)),
                'is_default' => 0,
                'is_system' => 0,
                'created_by_admin_id' => (int) $admin['id'],
                'updated_by_admin_id' => (int) $admin['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Audit::log('admin', (int) $admin['id'], 'prompt_template.create', 'prompt_template', $id, $this->request->ip());
            return Web::redirect('/admin/prompts?group=inspiration', '灵感模板已保存。');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/prompts/new', $exception->getMessage(), 'error');
        }
    }

    public function editPage(int $id, PromptTemplateService $prompts, AdminPromptManagementService $management): Response
    {
        $template = Db::name('prompt_templates')->where('id', $id)->find();
        if (!$template) {
            return Web::redirect('/admin/prompts', '模板不存在。', 'error');
        }
        $productCode = $management->productForTemplate($id);
        if ($productCode !== null) {
            return Response::create('/admin/prompts/core/' . rawurlencode($productCode) . '/' . $id . '/edit', 'redirect');
        }
        if ((string) $template['scene'] !== PromptTemplateService::SCENE_FREE) {
            return Web::redirect('/admin/prompts', '该模板不在当前提示词管理范围内。', 'error');
        }
        $admin = $this->admin();
        return $this->render('admin/prompt_form', compact('admin', 'template', 'prompts') + [
            'formAction' => '/admin/prompts/' . $id, 'isEdit' => true, 'pageTitle' => '编辑灵感模板',
        ]);
    }

    public function update(int $id, PromptTemplateService $prompts): Response
    {
        $admin = $this->admin();
        $template = Db::name('prompt_templates')->where('id', $id)->where('scene', PromptTemplateService::SCENE_FREE)->find();
        if (!$template) {
            return Web::redirect('/admin/prompts?group=inspiration', '灵感模板不存在。', 'error');
        }
        try {
            $data = $this->validatedFreeData($prompts, $template);
            Db::name('prompt_templates')->where('id', $id)->update($data + [
                'is_customized' => (int) ($template['is_system'] ?? 0) === 1 ? 1 : (int) ($template['is_customized'] ?? 0),
                'updated_by_admin_id' => (int) $admin['id'],
                'updated_at' => Web::now(),
            ]);
            Audit::log('admin', (int) $admin['id'], 'prompt_template.update', 'prompt_template', $id, $this->request->ip());
            return Web::redirect('/admin/prompts?group=inspiration', '灵感模板已更新。');
        } catch (Throwable $exception) {
            return Web::redirect('/admin/prompts/' . $id . '/edit', $exception->getMessage(), 'error');
        }
    }

    public function restore(int $id, PromptTemplateService $prompts, AdminPromptManagementService $management): Response
    {
        $admin = $this->admin();
        $template = Db::name('prompt_templates')->where('id', $id)->where('is_system', 1)->find();
        $definition = $template ? $prompts->builtInDefinition((string) $template['code']) : null;
        if (!$template || !$definition) {
            return Web::redirect('/admin/prompts', '找不到该模板的系统默认内容。', 'error');
        }
        Db::name('prompt_templates')->where('id', $id)->update([
            'name' => (string) $definition['name'],
            'content' => (string) $definition['content'],
            'is_customized' => 0,
            'updated_by_admin_id' => (int) $admin['id'],
            'updated_at' => Web::now(),
        ]);
        Audit::log('admin', (int) $admin['id'], 'prompt_template.restore_default', 'prompt_template', $id, $this->request->ip());
        $productCode = $management->productForTemplate($id);
        return Web::redirect($productCode ? '/admin/prompts?product=' . rawurlencode($productCode) : '/admin/prompts?group=inspiration', '模板已恢复系统默认内容。');
    }

    public function toggleStatus(int $id): Response
    {
        $admin = $this->admin();
        $template = Db::name('prompt_templates')->where('id', $id)->where('scene', PromptTemplateService::SCENE_FREE)->find();
        if (!$template) {
            return Web::redirect('/admin/prompts?group=inspiration', '灵感模板不存在。', 'error');
        }
        $status = (string) $template['status'] === 'active' ? 'inactive' : 'active';
        if ($status === 'active') {
            $this->assertPublishableFreeTemplate((string) $template['name'], (string) $template['content']);
        }
        Db::name('prompt_templates')->where('id', $id)->update([
            'status' => $status, 'updated_by_admin_id' => (int) $admin['id'], 'updated_at' => Web::now(),
        ]);
        return Web::redirect('/admin/prompts?group=inspiration', $status === 'active' ? '灵感模板已上架。' : '灵感模板已下架。');
    }

    public function delete(int $id): Response
    {
        $admin = $this->admin();
        $template = Db::name('prompt_templates')->where('id', $id)->where('scene', PromptTemplateService::SCENE_FREE)->find();
        if (!$template || (int) ($template['is_system'] ?? 0) === 1) {
            return Web::redirect('/admin/prompts?group=inspiration', '内置模板不能删除。', 'error');
        }
        Db::name('prompt_templates')->where('id', $id)->delete();
        Audit::log('admin', (int) $admin['id'], 'prompt_template.delete', 'prompt_template', $id, $this->request->ip());
        return Web::redirect('/admin/prompts?group=inspiration', '灵感模板已删除。');
    }

    private function validatedFreeData(PromptTemplateService $prompts, ?array $existing = null): array
    {
        $name = trim((string) $this->request->post('name', ''));
        $content = trim((string) $this->request->post('content', ''));
        $assetType = $existing ? (string) $existing['asset_type'] : (string) $this->request->post('asset_type', 'free_product');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new \RuntimeException('模板名称应为 2 到 160 个字符。');
        }
        if (!array_key_exists($assetType, $prompts->freeCategories())) {
            throw new \RuntimeException('请选择有效的灵感模板分类。');
        }
        if (mb_strlen($content) < 20 || mb_strlen($content) > 20000) {
            throw new \RuntimeException('提示词模板应为 20 到 20000 个字符。');
        }
        $status = $this->request->post('publish') === '1' ? 'active' : 'inactive';
        if ($status === 'active') {
            $this->assertPublishableFreeTemplate($name, $content);
        }
        return [
            'name' => $name, 'scene' => PromptTemplateService::SCENE_FREE, 'platform' => '*',
            'asset_type' => $assetType, 'content' => $content, 'status' => $status,
        ];
    }

    private function assertPublishableFreeTemplate(string $name, string $content): void
    {
        $specific = preg_replace('/[\p{P}\p{S}\p{Z}\p{N}_]+/u', '', $content) ?? '';
        if (mb_strlen($specific) < 24 || preg_match('/^(?:模板|灵感|示例|默认|自由创作)(?:\s*\d+)?$/u', $name) === 1) {
            throw new \RuntimeException('上架的灵感模板必须使用清楚名称，并写明主体、场景和画面要求。');
        }
    }

    private function admin(): array
    {
        return Db::name('admin_users')->where('id', (int) Session::get('admin_user_id'))->find();
    }
}
